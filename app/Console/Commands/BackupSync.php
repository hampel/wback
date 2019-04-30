<?php namespace App\Console\Commands;

use File;
use Storage;
use App\Sync\SyncCmd;

class BackupSync extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:sync 
                                {source?} 
                                {--a|all : Process all sources} 
                                {--d|dry-run : Do everything except the actual backup}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync data to cloud storage';

	protected function handleSource($source, $name)
	{
    	if (!isset($source['sync']) || empty($source['sync']))
	    {
	    	$this->log('notice', "No sync source specified for {$name}");
	    	return;
	    }

 		if (!isset($source['destination']))
	    {
	    	$this->log('error', "No destination specified for {$name}");
	    	return;
	    }

 		$sync = is_array($source['sync']) ? $source['sync'] : [$source['sync']];

 		foreach ($sync as $path)
	    {
			if (!File::isDirectory($source['files']))
			{
				$this->log(
					'error',
					"Sync source [{$path}] does not exist for {$name}",
					"File source does not exist for {$name}",
					['source' => $source['files']]
				);
				continue;
			}

			$this->syncFiles($source, $path, $name);
	    }
	}

    protected function syncFiles($source, $path, $name)
    {
    	$destination = $this->buildDestination($source, $path);

    	$dry = $this->option('dry-run') ? '[Dry run] ' : '';

    	$this->log(
    	    'notice',
	        "{$dry}Syncing files from [{$path}] to cloud::[{$destination}]",
	        "{$dry}Backing up files to cloud storage",
	        ['source' => $path, 'destination' => $destination]
	    );

    	/** @var SyncCmd $builder */
    	$builder = app()->make(SyncCmd::class);

 	    $this->executeCommand(
 	        $builder->buildCmd($source['files'], $destination, $path, $this->option('dry-run'), $this->output),
            $builder->canDryRun()
        );
    }

    protected function buildDestination($source, $path)
    {
    	$destination = $source['destination'] . DIRECTORY_SEPARATOR . 'sync' . DIRECTORY_SEPARATOR . $path;
    	$prefix = config('sync.prefix');
    	if (!empty($prefix))
	    {
	    	$destination = $prefix . DIRECTORY_SEPARATOR . $destination;
	    }
    	return $destination;
    }
}
