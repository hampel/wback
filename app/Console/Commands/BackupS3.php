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
	    	$this->error("No destination specified for {$name}");
	    	return;
	    }

 		$backupPath = Storage::path($source['destination']);

		if (!File::isDirectory($backupPath))
		{
			$this->error("Backup path [{$backupPath}] does not exist for {$name}");
		}

		return $this->backupS3($source, $name);
	}

    protected function backupS3($source, $name)
    {
	    $this->info("Backing up files from [{$source['destination']}] to S3");

		$files = collect(Storage::allFiles($source['destination']));
		$files->each(function ($file) {
			try
			{
				if (Storage::disk('s3')->exists($file))
				{
					if(Storage::disk('backup')->size($file) != Storage::disk('s3')->size($file))
					{
						$this->warn("File s3::[{$file}] exists, but file sizes do not match, skipping");
						return;
					}
					elseif (Storage::disk('backup')->lastModified($file) > Storage::disk('s3')->lastModified($file))
					{
						$this->warn("File s3::[{$file}] exists, but source has been modified since destination was uploaded, skipping");
						return;
					}
					else
					{
						$this->comment("File s3::[{$file}] exists, skipping");
						return;
					}
				}

				$size = Storage::disk('backup')->size($file);
				$size_human = $this->human_filesize($size);

				if ($this->option('dry-run'))
				{
					$this->info("Sending file to S3: [{$file}] {$size_human}");
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

					$this->info("Sent file to S3: [{$file}] {$size_human} in {$time_human} seconds{$speed_human}");
				}
			}
			catch (\Exception $e)
			{
				$this->error($e->getMessage() . ($e->getCode() ? " [" . $e->getCode() . "]" : ""));
			}
		});
    }

	protected function human_filesize($bytes, $dec = 2)
	{
	    $size   = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
	    $factor = floor((strlen($bytes) - 1) / 3);

	    return sprintf("%.{$dec}f", $bytes / pow(1024, $factor)) . " " . @$size[$factor];
	}
}
