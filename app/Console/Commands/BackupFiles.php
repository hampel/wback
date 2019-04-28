<?php namespace App\Console\Commands;

use File;
use Storage;

class BackupFiles extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:files 
                                {source?} 
                                {--a|all : Process all sources} 
                                {--d|dry-run : Do everything except the actual backup}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup files';

	protected function handleSource($source, $name)
	{
    	if (!isset($source['files']) || empty($source['files']))
	    {
	    	$this->log('notice', "No files source specified for {$name}");
	    	return;
	    }

 		if (!isset($source['destination']) || empty($source['destination']))
	    {
	    	$this->log('error', "No destination specified for {$name}");
	    	return;
	    }

		if (!File::isDirectory($source['files']))
		{
			$this->log(
				'error',
				"File source [{$source['files']}] does not exist for {$name}",
				"File source does not exist for {$name}",
				['source' => $source['files']]
			);
		}

		return $this->backupFiles($source, $name);
	}

    protected function backupFiles($source, $name)
    {
    	$files = $source['files'];
    	$destination = $this->getDestination($source, $name,'files', '.zip');

    	$this->log(
    	    'notice',
	        "Backing up files from [{$files}] to [{$destination}]",
	        "Backing up files",
	        compact('files', 'destination')
	    );

	    $zip = config('backup.zip_path');

	    if ($this->output->isVerbose())
	    {
	    	$verbosity = ' --verbose';
	    }
	    elseif ($this->output->isQuiet())
	    {
	    	$verbosity = ' --quiet';
	    }
	    else
	    {
	    	$verbosity = '';
	    }

	    $outputPath = Storage::disk()->path($destination);
	    $exclude = $this->generateExcludes($source['exclude'] ?? []);

		$cmd = "cd {$files} && {$zip} -9{$verbosity} --recurse-paths --symlinks {$outputPath} .{$exclude}";

		$this->executeCommand($cmd);
    }

    protected function generateExcludes(array $excludes)
    {
    	$ex = collect($excludes)->transform(function ($value, $key) {
    		return preg_replace('/[\*]/', '\*', $value);
	    })->implode(' ');

    	return empty($ex) ? '' : " --exclude {$ex}";
    }
}
