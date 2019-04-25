<?php namespace App\Console\Commands;

use File;
use Storage;

class BackupS3 extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:s3 
                                {source?} 
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

 		$backupPath = Storage::path($source['destination']);

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

		$files = collect(Storage::allFiles($source['destination']));
		$files->each(function ($file) {
			try
			{
				if (Storage::disk('s3')->exists($file))
				{
					if(Storage::disk('backup')->size($file) != Storage::disk('s3')->size($file))
					{
						$this->log(
							'warning',
							"File s3::[{$file}] exists, but file sizes do not match, skipping",
							"File exists on S3, but file sizes do not match, skipping",
							compact('file')
						);
						return;
					}
					elseif (Storage::disk('backup')->lastModified($file) > Storage::disk('s3')->lastModified($file))
					{
						$this->log(
							'warning',
							"File s3::[{$file}] exists, but source has been modified since destination was uploaded, skipping",
							"File exists on S3, but source has been modified since destination was uploaded, skipping",
							compact('file')
						);
						return;
					}
					else
					{
						$this->log(
							'notice',
							"File s3::[{$file}] exists, skipping",
							"File exists on S3, skipping",
							compact('file')
						);
						return;
					}
				}

				$size = Storage::disk('backup')->size($file);
				$size_human = $this->human_filesize($size);

				if ($this->option('dry-run'))
				{
					$this->log(
						'notice',
						"[Dry run] Sending file to S3: [{$file}] {$size_human}",
						"Sending file to S3",
						compact('file', 'size', 'size_human')
					);
				}
				else
				{
					$start = microtime(true);
					Storage::disk('s3')->getDriver()->writeStream(
						$file,
						Storage::disk('backup')->getDriver()->readStream($file)
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
						"Sent file to S3: [{$file}] {$size_human} in {$time_human} seconds{$speed_human}",
						"Sent file to S3",
						compact('file', 'size', 'size_human', 'time_human', 'speed_human')
					);

				}
			}
			catch (\Exception $e)
			{
				$this->log('error', $e->getMessage() . ($e->getCode() ? " [" . $e->getCode() . "]" : ""));
			}
		});
    }
}
