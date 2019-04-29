<?php namespace App\Sync;

use Illuminate\Console\OutputStyle;

class S3Cmd implements SyncCmd
{
	public function buildCmd($source, $destination, $path, $dryrun = false, OutputStyle $output): string
	{
    	$awscli = config('sync.services.s3.awscli');
    	$sync_bucket = config('sync.services.s3.bucket');

    	$dry = $dryrun ? ' --dryrun' : '';
 	    $quiet = $output->isQuiet() ? ' --quiet' : '';
 	    $storage_class = ' --storage-class ' . config('sync.services.s3.storage_class');

 	    return "cd {$source} && {$awscli} s3 sync {$path} s3://{$sync_bucket}/{$destination}{$dry}{$quiet}{$storage_class}";
	}

	public function canDryRun() : bool
	{
		return true;
	}
}
