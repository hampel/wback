<?php namespace App\Console\Commands;

use Carbon\Carbon;
use File;
use Storage;

class BackupClean extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:clean 
                                {source?} 
                                {--a|all : Process all sources} 
                                {--d|dry-run : Do everything except the actual clean}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old backup files';

	protected function handleSource($source, $name)
	{
 		if (!isset($source['destination']) || empty($source['destination']))
	    {
	    	$this->log('error', "No destination specified for {$name}");
	    	return;
	    }

 		if (isset($source['files']) && !empty($source['files']))
	    {
		    $this->clean($source, $name, 'files');
	    }
 		else
	    {
	    	$this->log('notice', "No files source specified for {$name}");
	    }

 		if (isset($source['database']) && !empty($source['database']))
	    {
		    $this->clean($source, $name, 'database');
	    }
 		else
	    {
	    	$this->log('notice', "No database source specified for {$name}");
	    }
	}

    protected function clean($source, $name, $type)
    {
    	$path = $source['destination'] . DIRECTORY_SEPARATOR . $type;
    	if (!Storage::disk('backup')->exists($path))
	    {
	    	$this->log(
	    	    'error',
		        "Path [{$path}] does not exist",
		        "Path does not exist",
		        compact('path')
		    );
	    	return;
	    }

    	$this->log(
    	    'notice',
	        "Cleaning up old backups from [{$path}]",
	        "Cleaning up old backups",
	        compact('path')
	    );

    	$days = config('backup.keeponly_days');

		collect(Storage::disk('backup')->allFiles($path))
			->reject(function ($path) use ($days) {
				return Storage::disk('backup')->lastModified($path) > Carbon::now()->subDays($days)->timestamp;
			})
			->each(function ($path) {
				$this->deleteFile($path);
			});
    }

    protected function deleteFile($path)
    {
		if ($this->option('dry-run'))
		{
			$this->log(
				'notice',
				"[Dry run] Deleting [{$path}]",
				"[Dry run] Deleting file",
				compact('path')
			);
		}
		else
		{
			$this->log(
				'notice',
				"Deleting old backup file [{$path}]",
				"Deleting old backup file",
				compact('path')
			);

	        Storage::disk('backup')->delete($path);
		}
    }
}
