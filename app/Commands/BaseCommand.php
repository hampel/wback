<?php namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Yosymfony\Toml\Exception\ParseException;
use Yosymfony\Toml\Toml;

abstract class BaseCommand extends Command
{
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
 	    $site = $this->argument('site');

		if ($this->option('dry-run'))
		{
			$this->comment("Dry run only - no action will be taken");
		}

        try
        {
            $sitesPath = config('backup.sites_path');
            $sites = File::exists($sitesPath) ? Toml::parseFile($sitesPath) : null;
        }
        catch (ParseException $e)
        {
            $this->log('error', $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($sites))
        {
            $this->error("No sites found at: {$sitesPath}");
            return Command::FAILURE;
        }

        try {
            if (!empty($site)) {
                $config = $sites[$site] ?? null;
                if (empty($config)) {
                    $this->log(
                        'error',
                        "Could not find definition for site: {$site}",
                        "Could not find definition for site",
                        ['site' => $site]
                    );
                    return Command::FAILURE;
                }

                $this->handleSite($config, $site);

                return Command::SUCCESS;
            }

            if ($this->option('all')) {
                foreach ($sites as $name => $config) {
                    $this->section($name);

                    $this->handleSite($config, $name);
                }

                return Command::SUCCESS;
            }
        }
        catch (\RuntimeException $e)
        {
            $this->log('error', $e->getMessage());
            return Command::FAILURE;
        }

        // nothing to do - show usage information and return failure

        $this->log('error', "No site provided - specify site name as parameter, or -a|--all to process all configured sites");
        $this->section("Usage:");

        $helper = new DescriptorHelper();
        $helper->describe($this->output, $this);

        $this->section("Configured sites:");

        $this->call('sites');

        return Command::FAILURE;
    }

    /**
     * @param array $site
     * @param string $name
     * @return void
     * @throws \RuntimeException
     */
	abstract protected function handleSite(array $site, string $name) : void;

    /**
     * @param array $site site config from toml
     * @param string $name site short name
     * @param string $type type of backup (files|database)
     * @paran bool $createPaths set to true to create missing paths
     * @return string destination path
     * @throws \RuntimeException
     */
    protected function getDestinationPath(array $site, string $name, string $type, bool $createPaths = true) : string
    {
        if (empty($site['domain'])) {
            throw new \RuntimeException("No domain specified for {$name}");
        }

        $domain = $site['domain'];

        if ($createPaths)
        {
            if (!Storage::disk('backup')->exists($domain))
            {
                $this->log(
                    'info',
                    "Creating directory [{$domain}]",
                    "Creating directory",
                    compact('domain')
                );
                Storage::disk('backup')->makeDirectory($domain);
            }

            $typePath = $domain . DIRECTORY_SEPARATOR . $type;
            if (!Storage::disk('backup')->exists($typePath))
            {
                $this->log(
                    'info',
                    "Creating directory [{$typePath}]",
                    "Creating directory",
                    ['directory' => $typePath]
                );
                Storage::disk('backup')->makeDirectory($typePath);
            }
        }

        return $domain . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
    }

    /**
     * @param array $site site config from toml
     * @param string $name site short name
     * @param string $type type of backup (files|database)
     * @param string $suffix filename suffix (zip|gzip)
     * @return string destination filename
     * @throws \RuntimeException
     */
    protected function getDestinationFile(array $site, string $name, string $type, string $suffix) : string
    {
        $basePath = $this->getDestinationPath($site, $name, $type);

    	$filenameBase = "{$name}." . Carbon::today(new \DateTimeZone(config('app.timezone')))->format("Ymd");

    	$filename = "{$filenameBase}{$suffix}";
    	$count = 1;
    	while (Storage::disk('backup')->exists("{$basePath}{$filename}"))
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

    protected function executeCommand(string $command, string $path = null, bool $override = false) : bool
    {
        if (empty($path))
        {
            $path = storage_path();
        }

    	$prefix = $this->option('dry-run') ? "[Dry run] " : "";

		$this->log('debug', "{$prefix}Executing command [{$command}]", "{$prefix}Executing command", compact('command'));

		if ($this->option('dry-run') && !$override)
		{
			return true;
		}

        $result = Process::forever()->path($path)->run($command, function (string $type, string $output) {
            $this->getOutput()->write($output, false);
        })->throw();

        return $result->successful();
    }

    protected function chmod($path, $mode = 0660)
    {
    	if (!File::exists($path))
	    {
	    	$this->log('warning', "Path does not exist when changing permissions [{$path}]", "Path does exist when changing permissions", compact('path'));
	    	return;
	    }

    	if (!File::chmod($path, $mode))
	    {
	    	$this->log('warning', "Could not change permissions on [{$path}] to [{$mode}]", "Could not change permissions", compact('path', 'mode'));
	    }
    }

    protected function getVerbosity() : string
    {
        return match (true) {
            $this->output->isVerbose() => ' --verbose',
            $this->output->isQuiet() => ' --quiet',
            default => '',
        };
    }

    /**
     * @return int offset in hours to execute this command on schedule
     */
    abstract protected function scheduleOffset() : int;

    protected function getScheduleTime() : string
    {
        $scheduleStart = config('backup.schedule_start');
        $offset = $this->scheduleOffset();
        return sprintf("%d:00", $scheduleStart + $offset);
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
