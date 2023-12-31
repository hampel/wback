<?php namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

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
    	if (!Storage::disk()->exists($path))
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

    	$cutoff = Carbon::now()->subDays(config('backup.keeponly_days', 7))->timestamp;

		collect(Storage::disk()->allFiles($path))
			->reject(function ($path) use ($cutoff) {
				return Storage::disk()->lastModified($path) > $cutoff;
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

	        Storage::disk()->delete($path);
		}
    }
}
