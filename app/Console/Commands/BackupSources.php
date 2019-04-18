<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupSources extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:sources {source?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List backup sources';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
    	$source = $this->argument('source');

    	if (!empty($source))
	    {
	    	$config = config("backup.sources.{$source}");
	    	if (is_null($config))
		    {
		    	$this->error("Could not find definition for source: {$source}");
		    	return;
		    }
 	        $this->outputSource($config);
	    }
    	else
	    {
	    	$sources = config("backup.sources");
	        foreach ($sources as $name => $source)
	        {
				$this->info($name);
				$this->outputSource($source);
	        }
	    }
    }

    protected function outputSource($source)
    {
        foreach ($source as $key => $data)
        {
            if (!empty($data))
	        {
	            if (is_array($data))
		        {
		            $this->line("    <comment>{$key}</comment>:");
		            foreach ($data as $d)
			        {
			            $this->line("        {$d}");
			        }
		        }
	            else
		        {
			        $this->line("    <comment>{$key}</comment>: {$data}");
		        }
	        }
        }

        $this->line('');
    }
}
