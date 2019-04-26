<?php namespace App\Console\Commands;

use File;
use Storage;

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
    protected $description = 'Sync data to S3';

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
    	$files = $source['files'];
    	$destination = "{$source['destination']}/{$path}";

    	$this->log(
    	    'notice',
	        "Syncing files from [{$path}] to s3::[{$destination}]",
	        "Backing up files to S3",
	        ['source' => $path, 'destination' => $destination]
	    );

    	$awscli = config('backup.awscli_path');
    	$sync_bucket = config('backup.sync_bucket');

    	$dryrun = $this->option('dry-run') ? ' --dryrun' : '';
 	    $verbosity = $this->output->isQuiet() ? ' --quiet' : '';
 	    $storage_class = ' --storage-class ' . config('backup.sync_storage_class');

 	    $cmd = "cd {$files} && {$awscli} s3 sync {$path} s3://{$sync_bucket}/{$destination}{$dryrun}{$verbosity}{$storage_class}";

 	    $this->executeCommand($cmd);
    }

}
