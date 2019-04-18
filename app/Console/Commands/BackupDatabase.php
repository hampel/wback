<?php namespace App\Console\Commands;

use Storage;

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

	protected function handleSource($config, $name)
	{
    	if (!isset($source['database']) || empty($source['database']))
	    {
	    	$this->info("No database specified for {$name}");
	    	return;
	    }

		if (!isset($source['destination']))
	    {
	    	$this->error("No destination specified for {$name}");
	    	return;
	    }

		return $this->backupDatabase($config, $name);
	}

    protected function backupDatabase($source, $name)
    {
    	$db = $source['database'];

    	$destination = $this->getDestination($source, $name,'database', '.sql.gz');

    	$this->info("Backing up database [{$db}] to [{$destination}]");

    	$hostname = isset($source['hostname']) ? " -h{$source['hostname']}" : '';
		$verbosity = $this->output->isVerbose() ? ' --verbose' : '';
		$charset = isset($source['charset']) ? " --default-character-set={$source['charset']}" : '';
		$mysqldump = config('backup.mysql.dump_path');
		$gzip = config('backup.gzip_path');
		$outputPath = Storage::path($destination); // TODO: find base path of disk

		$cmd = "{$mysqldump} --opt{$verbosity}{$charset}{$hostname} {$db} | {$gzip} -c -f > {$outputPath}";

		$this->info("executing command [{$cmd}]");

		if ($this->option('dry-run'))
		{
			$this->comment("Dry run only - no backup performed");
		}
		else
		{
			$retvar = 0;

			$command_output = system("{$cmd} 2>&1", $retvar);
			if ($retvar != 0)
			{
				$this->error("non-zero return code executing [{$cmd}]");
				$this->error($command_output);
			}
		}
    }
}
