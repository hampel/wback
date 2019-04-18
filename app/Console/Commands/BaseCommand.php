<?php namespace App\Console\Commands;

use Storage;
use Carbon\Carbon;
use Illuminate\Console\Command;

abstract class BaseCommand extends Command
{
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
	    $name = $this->argument('source');

	    if (!empty($name))
	    {
	        $config = config("backup.sources.{$name}");
	        if (is_null($config))
		    {
		        $this->error("Could not find definition for source: {$name}");
		        return;
		    }
            $this->handleSource($config, $name);
	    }
        elseif ($this->option('all'))
	    {
	        $sources = config("backup.sources");
	        foreach ($sources as $name => $config)
	        {
				$this->handleSource($config, $name);
	        }
	    }
	    else
	    {
	    	$this->info("No sources provided");
	    	$this->line('');

	        $helper = new DescriptorHelper();
	        $helper->describe($this->output, $this);

	        $this->line('');
	        $this->line('Configured sources:');
	        $this->line('');

	        $this->call('backup:sources');
	    }
    }

	abstract protected function handleSource($config, $name);

    protected function getDestination($source, $name, $type, $suffix)
    {
    	$destination = $source['destination'];

    	$this->info("Processing {$type} for {$name}");

    	if (!Storage::exists($destination))
	    {
	    	Storage::makeDirectory($destination);
	    }

    	if (!Storage::exists($destination . DIRECTORY_SEPARATOR . $type))
	    {
	    	Storage::makeDirectory($destination . DIRECTORY_SEPARATOR . $type);
	    }

    	$basePath = $destination . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;

    	$filenameBase = "{$name}." . Carbon::today(new \DateTimeZone(config('app.timezone')))->format("Ymd");

    	$filename = "{$filenameBase}{$suffix}";
    	$count = 1;
    	while (Storage::exists("{$basePath}{$filename}"))
	    {
	    	$count++;
	    	$filename = "{$filenameBase}-{$count}{$suffix}";
	    }

	    return "{$basePath}{$filename}";
    }
}
