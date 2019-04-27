<?php namespace App\Console\Commands;

use File;
use Cache;
use Storage;
use Carbon\Carbon;

class BackupS3 extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:s3 
                                {source?} 
                                {--f|force : Force run, regardless of last run time}
                                {--a|all : Process all sources} 
                                {--d|dry-run : Do everything except the actual backup}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send backup files to S3';

	protected function handleSource($source, $name)
	{
 		if (!isset($source['destination']) || empty($source['destination']))
	    {
	    	$this->log('error', "No destination specified for {$name}");
	    	return;
	    }

 		$backupPath = Storage::disk('backup')->path($source['destination']);

		if (!File::isDirectory($backupPath))
		{
			$this->log(
				'error',
				"Backup path [{$backupPath}] does not exist for {$name}",
				"Backup path does not exist for {$name}",
				['path' => $backupPath]
			);
			return;
		}

		return $this->backupS3($source, $name);
	}

    protected function backupS3($source, $name)
    {
    	$this->log(
    	    'notice',
	        "Backing up files from [{$source['destination']}] to S3",
	        "Backing up files to S3",
	        ['source' => $source['destination']]
	    );

    	$lastUpdated = $this->option('force') ? 0 : $this->lastUpdated($name);

		collect(Storage::disk('backup')->allFiles($source['destination']))
			->transform(function ($path) {
				return ['path' => $path, 'modified' => Storage::disk('backup')->lastModified($path)];
			})
			->reject(function ($file) use ($lastUpdated) {
				return $lastUpdated > 0 && $file['modified'] <= $lastUpdated; // remove from collection if true
			})
			->reject(function ($file) {
				return $this->fileExists($file); // remove from collection if file already exists on S3
			})
			->sortBy('modified')
			->each(function ($file) use (&$lastUpdated) {
				return $this->uploadFile($file, $lastUpdated); // if upload fails, it will return false and we will stop
			});

		if ($lastUpdated > 0)
		{
			Cache::put($name, $lastUpdated, config('backup.last_update_cache'));
		}
    }

    protected function lastUpdated($name)
    {
    	$lastUpdate = Cache::get($name, 0);

		$format = 'd-M-Y h:i A \G\M\TP';
    	$last_upload = ($lastUpdate > 0) ? Carbon::createFromTimestampUTC($lastUpdate)->setTimezone(config('app.timezone'))->format($format) : 'never';

	    $this->log(
	        'info',
	        "Last upload for {$name} to S3: {$last_upload}",
	        "Last upload to S3",
	        compact('name', 'last_upload')
	    );

    	return $lastUpdate;
    }

    protected function fileExists($file)
    {
    	$path = $file['path'];

		try
		{
			if (Storage::disk('s3')->exists($path))
			{
				if(Storage::disk('backup')->size($path) != Storage::disk('s3')->size($path))
				{
					$this->log(
						'warning',
						"File s3::[{$path}] exists, but file sizes do not match, skipping",
						"File exists on S3, but file sizes do not match, skipping",
						compact('path')
					);
				}
				elseif (Storage::disk('backup')->lastModified($path) > Storage::disk('s3')->lastModified($path))
				{
					$this->log(
						'warning',
						"File s3::[{$path}] exists, but source has been modified since destination was uploaded, skipping",
						"File exists on S3, but source has been modified since destination was uploaded, skipping",
						compact('path')
					);
				}
				else
				{
					$this->log(
						'notice',
						"File s3::[{$path}] exists, skipping",
						"File exists on S3, skipping",
						compact('path')
					);
				}

				return true; // reject
			}
		}
		catch (\Exception $e)
		{
			$this->log('error', $e->getMessage() . ($e->getCode() ? " [" . $e->getCode() . "]" : ""));
		}

		return false; // keep
    }

    protected function uploadFile(array $file, &$lastUpdated) : bool
    {
    	$path = $file['path'];
    	$modified = $file['modified'];

	    try
	    {
		    $size = Storage::disk('backup')->size($path);
			$size_human = $this->human_filesize($size);

			if ($this->option('dry-run'))
			{
				$this->log(
					'notice',
					"[Dry run] Sending file to S3: [{$path}] {$size_human}",
					"[Dry run] Sending file to S3",
					compact('path', 'size', 'size_human')
				);
			}
			else
			{
				$start = microtime(true);
				Storage::disk('s3')->getDriver()->writeStream(
					$path,
					Storage::disk('backup')->getDriver()->readStream($path)
				);
				$time = microtime(true) - $start;
				$time_human = number_format($time, 2);

				$speed_human = '';
				if ($time > 0)
				{
					$speed = intval(round($size / $time));
					$speed_human = " (" . $this->human_filesize($speed) . "/s)";
				}

				$this->log(
					'notice',
					"Sent file to S3: [{$path}] {$size_human} in {$time_human} seconds{$speed_human}",
					"Sent file to S3",
					compact('path', 'size', 'size_human', 'time_human', 'speed_human')
				);

				$lastUpdated = $modified;
			}
		}
		catch (\Exception $e)
		{
			$this->log('error', $e->getMessage() . ($e->getCode() ? " [" . $e->getCode() . "]" : ""));

			return false; // stop uploading files
		}

		return true; // continue
    }
}
