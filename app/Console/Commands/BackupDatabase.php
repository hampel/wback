<?php namespace App\Console\Commands;


use Illuminate\Support\Facades\Storage;

class BackupDatabase extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:database
                                {source?}
                                {--a|all : Process all sources}
                                {--d|dry-run : Do everything except the actual backup}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup databases';

	protected function handleSource($source, $name)
	{
    	if (!isset($source['database']) || empty($source['database']))
	    {
	    	$this->log('notice', "No database source specified for {$name}");
	    	return;
	    }

		if (!isset($source['destination']) || empty($source['destination']))
	    {
	    	$this->log('error', "No destination specified for {$name}");
	    	return;
	    }

		return $this->backupDatabase($source, $name);
	}

    protected function backupDatabase($source, $name)
    {
    	$database = $source['database'];

    	$destination = $this->getDestination($source, $name,'database', '.sql.gz');

	    $mysqldump = config('backup.mysql.dump_path');
	    $verbosity = $this->output->isVerbose() ? ' --verbose' : '';
	    $charset = isset($source['charset']) ? " --default-character-set={$source['charset']}" : '';
	    $hostname = isset($source['hostname']) ? " -h{$source['hostname']}" : '';
		$gzip = config('backup.gzip_path');
		$outputPath = Storage::disk()->path($destination);

		$cmd = "{$mysqldump} --opt{$verbosity}{$charset}{$hostname} {$database} | {$gzip} -c -f > {$outputPath}";

        $this->log(
            'notice',
            "Backing up database [{$database}] to [{$destination}]",
            "Backing up database",
            compact('database', 'destination', 'cmd')
        );

		$this->executeCommand($cmd);
		$this->chmod($outputPath);
    }
}
