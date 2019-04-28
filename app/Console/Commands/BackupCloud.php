<?php namespace App\Console\Commands;

use File;
use Cache;
use Storage;
use Carbon\Carbon;

class BackupCloud extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:cloud 
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
    protected $description = 'Send backup files to cloud storage';

	protected function handleSource($source, $name)
	{
 		if (!isset($source['destination']) || empty($source['destination']))
	    {
	    	$this->log('error', "No destination specified for {$name}");
	    	return;
	    }

 		$backupPath = Storage::disk()->path($source['destination']);

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

		return $this->backupCloud($source, $name);
	}

    protected function backupCloud($source, $name)
    {
    	$this->log(
    	    'notice',
	        "Sending backup files from [{$source['destination']}] to cloud storage",
	        "Sending backup files to cloud storage",
	        ['source' => $source['destination']]
	    );

    	$lastUpdated = $this->option('force') ? 0 : $this->lastUpdated($name);

		collect(Storage::disk()->allFiles($source['destination']))
			->transform(function ($path) {
				return ['path' => $path, 'modified' => Storage::disk()->lastModified($path)];
			})
			->reject(function ($file) use ($lastUpdated) {
				return $lastUpdated > 0 && $file['modified'] <= $lastUpdated; // remove from collection if true
			})
			->reject(function ($file) {
				return $this->fileExists($file); // remove from collection if file already exists on cloud storage
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
	        "Last upload for {$name} to cloud storage: {$last_upload}",
	        "Last upload to cloud storage",
	        compact('name', 'last_upload')
	    );

    	return $lastUpdate;
    }

    protected function fileExists($file)
    {
    	$path = $file['path'];

		try
		{
			if (Storage::cloud()->exists($path))
			{
				if(Storage::disk()->size($path) != Storage::cloud()->size($path))
				{
					$this->log(
						'warning',
						"File cloud::[{$path}] exists, but file sizes do not match, skipping",
						"File exists on cloud storage, but file sizes do not match, skipping",
						compact('path')
					);
				}
				elseif (Storage::disk()->lastModified($path) > Storage::cloud()->lastModified($path))
				{
					$this->log(
						'warning',
						"File cloud::[{$path}] exists, but source has been modified since destination was uploaded, skipping",
						"File exists on cloud storage, but source has been modified since destination was uploaded, skipping",
						compact('path')
					);
				}
				else
				{
					$this->log(
						'notice',
						"File cloud::[{$path}] exists, skipping",
						"File exists on cloud storage, skipping",
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
		    $size = Storage::disk()->size($path);
			$size_human = $this->human_filesize($size);

			if ($this->option('dry-run'))
			{
				$this->log(
					'notice',
					"[Dry run] Sending backup file to cloud storage: [{$path}] {$size_human}",
					"[Dry run] Sending backup file to cloud storage",
					compact('path', 'size', 'size_human')
				);
			}
			else
			{
				$start = microtime(true);
				Storage::cloud()->getDriver()->writeStream(
					$path,
					Storage::disk()->getDriver()->readStream($path)
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
					"Sent file to cloud storage: [{$path}] {$size_human} in {$time_human} seconds{$speed_human}",
					"Sent file to cloud storage",
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
