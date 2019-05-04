<?php namespace App\Console\Commands;

use Log;
use Storage;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

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

		if ($this->option('dry-run'))
		{
			$this->comment("Dry run only - no action will be taken");
		}

	    if (!empty($name))
	    {
	        $config = config("backup.sources.{$name}");
	        if (is_null($config))
		    {
		    	$this->log(
		    	    'error',
			        "Could not find definition for source: {$name}",
			        "Could not find definition for source",
			        ['source' => $name]
			    );
		        return;
		    }
            $this->handleSource($config, $name);
	    }
        elseif ($this->option('all'))
	    {
	        $sources = config("backup.sources");
	        foreach ($sources as $name => $config)
	        {
	        	$this->section($name);
				$this->handleSource($config, $name);
	        }
	    }
	    else
	    {
	    	$this->log('error', "No sources provided");
	    	$this->section("Usage:");

	        $helper = new DescriptorHelper();
	        $helper->describe($this->output, $this);

	        $this->section("Configured sources:");

	        $this->call('backup:sources');
	    }
    }

	abstract protected function handleSource($config, $name);

    protected function getDestination($source, $name, $type, $suffix)
    {
    	$destination = $source['destination'];

    	$this->log('info', "Processing {$type} for {$name}");

    	if (!Storage::disk()->exists($destination))
	    {
	    	$this->log(
	    	    'info',
		        "Creating directory [{$destination}]",
		        "Creating directory",
		        compact('destination')
		    );
	    	Storage::disk()->makeDirectory($destination);
	    }

    	$destination_and_type = $destination . DIRECTORY_SEPARATOR . $type;
    	if (!Storage::disk()->exists($destination_and_type))
	    {
	    	$this->log(
	    	    'info',
		        "Creating directory [{$destination}]",
		        "Creating directory",
		        ['directory' => $destination_and_type]
		    );
	    	Storage::disk()->makeDirectory($destination_and_type);
	    }

    	$basePath = $destination . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;

    	$filenameBase = "{$name}." . Carbon::today(new \DateTimeZone(config('app.timezone')))->format("Ymd");

    	$filename = "{$filenameBase}{$suffix}";
    	$count = 1;
    	while (Storage::disk()->exists("{$basePath}{$filename}"))
	    {
	    	$this->log(
	    	    'debug',
		        "[{$basePath}{$filename}] already exists, incrementing",
		        "Destination already exists, incrementing",
		        ['destination' => "{$basePath}{$filename}"]
		    );

	    	$count++;
	    	$filename = "{$filenameBase}-{$count}{$suffix}";
	    }

	    return "{$basePath}{$filename}";
    }

    protected function executeCommand($command, $override = false)
    {
    	$prefix = $this->option('dry-run') ? "[Dry run] " : "";

		$this->log('info', "{$prefix}Executing command [{$command}]", "{$prefix}Executing command", compact('command'));

		if ($this->option('dry-run') && !$override)
		{
			return;
		}
		else
		{
			$retvar = 0;

			$output = system("{$command} 2>&1", $retvar);
			if ($retvar != 0)
			{
				$this->log('error', "Non-zero return code executing command [{$command}]", "Non-zero return code executing command", compact('command', 'output'));
				$this->error($output);
			}
		}
    }

	protected function human_filesize($bytes, $dec = 2)
	{
	    $size   = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
	    $factor = floor((strlen($bytes) - 1) / 3);

	    return sprintf("%.{$dec}f", $bytes / pow(1024, $factor)) . " " . @$size[$factor];
	}

    protected function log($level, $message, $logMessage = null, $context = [])
    {
    	$verbosityMap = [
    	    'debug' => OutputInterface::VERBOSITY_DEBUG,
	        'info' => OutputInterface::VERBOSITY_VERBOSE,
	        'notice' => OutputInterface::VERBOSITY_NORMAL,
	        'warning' => OutputInterface::VERBOSITY_NORMAL,
	        'error' => OutputInterface::VERBOSITY_QUIET,
	        'critical' => OutputInterface::VERBOSITY_QUIET,
	        'alert' => OutputInterface::VERBOSITY_QUIET,
	        'emergency' => OutputInterface::VERBOSITY_QUIET,
	    ];

    	$styleMap = [
     	    'debug' => null,
	        'info' => 'info',
	        'notice' => 'comment',
	        'warning' => 'comment',
	        'error' => 'error',
	        'critical' => 'error',
	        'alert' => 'error',
	        'emergency' => 'error',
	    ];

    	$logMessage = $logMessage ?? $message;
    	$verbosity = $verbosityMap[$level] ?? 'warning';
    	$style = $styleMap[$level] ?? null;

		Log::log($level, $logMessage, $context);
		$this->line($message, $style, $verbosity);
    }

    protected function section($string, $verbosity = null)
    {
        if (! $this->output->getFormatter()->hasStyle('section')) {
            $style = new OutputFormatterStyle('cyan');

            $this->output->getFormatter()->setStyle('section', $style);
        }

        $this->output->newLine();
        $this->line($string, 'section', $verbosity);
        $this->line(str_repeat('-', strlen($string)), 'section', $verbosity);
        $this->output->newLine();
    }
}
