<?php namespace App\Commands;

use App\Support\LocksBackups;
use App\Support\LogsToConsole;
use App\Support\SiteInventory;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Yosymfony\Toml\Exception\ParseException;

abstract class BaseCommand extends Command
{
    use LocksBackups, LogsToConsole;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
		if ($this->option('dry-run'))
		{
			$this->comment("Dry run only - no action will be taken");
		}

        if (!$this->acquireLock())
        {
            return Command::FAILURE;
        }

        try
        {
            return $this->process();
        }
        finally
        {
            $this->releaseLock();
        }
    }

    /**
     * Work through the sites this command was asked to process
     *
     * @return int exit code
     */
    protected function process()
    {
 	    $site = $this->argument('site');

        $inventory = app(SiteInventory::class);

        try
        {
            $sites = $inventory->all();
        }
        catch (ParseException $e)
        {
            $this->log('error', $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($sites))
        {
            $this->error("No sites found at: " . $inventory->path());
            return Command::FAILURE;
        }

        if ($this->option('all')) {
            if (!empty($site)) {
                $this->log(
                    'notice',
                    "Processing all sites - ignoring site argument [{$site}]",
                    "Processing all sites - ignoring site argument",
                    ['site' => $site]
                );
            }

            $failed = false;

            foreach ($sites as $name => $config) {
                $this->section($name);

                if (!$this->runSite($config, $name)) {
                    $failed = true;
                }
            }

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

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

            return $this->runSite($config, $site) ? Command::SUCCESS : Command::FAILURE;
        }

        // nothing to do - show usage information and return failure

        $this->log('error', "No site provided - specify site name as parameter, or -a|--all to process all configured sites");
        $this->section("Usage:");

        $helper = new DescriptorHelper();
        $helper->describe($this->output, $this);

        $this->section("Configured sites:");

        $this->call('app:sites');

        return Command::FAILURE;
    }

    /**
     * Process a single site, reporting rather than propagating a failure so that
     * one broken site does not stop the rest of the sites being backed up
     *
     * @param array $site site config from toml
     * @param string $name site short name
     * @return bool false if the site failed
     */
    protected function runSite(array $site, string $name) : bool
    {
        try
        {
            $this->processSite($site, $name);
        }
        catch (\RuntimeException $e)
        {
            $this->log('error', $e->getMessage());
            return false;
        }

        return true;
    }

    protected function processSite(array $site, string $name) : void
    {
        if (empty($site['domain'])) {
            throw new \RuntimeException("No domain specified for {$name}");
        }

        $this->handleSite($site, $name);
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
        // a dry run reports where the backup would go without building the tree to put it in
        $basePath = $this->getDestinationPath($site, $name, $type, !$this->option('dry-run'));

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
    	$prefix = $this->option('dry-run') ? "[Dry run] " : "";

		$this->log('debug', "{$prefix}Executing command [{$command}]", "{$prefix}Executing command", compact('command'));

		if ($this->option('dry-run') && !$override)
		{
			return true;
		}

        // only the file backup cares where it runs from - everything else works with
        // absolute paths, and would rather inherit a working directory that exists
        $process = Process::forever();

        if (!empty($path))
        {
            $process = $process->path($path);
        }

        $result = $process->run($command, function (string $type, string $output) {
            $this->getOutput()->write($output, false);
        })->throw();

        return $result->successful();
    }

    /**
     * Run the command that produces a backup file, and clean up after it if it fails
     *
     * A command that dies partway leaves a partial file behind - the shell creates the
     * destination as soon as it opens the redirect - and a partial file sitting in the
     * backup directory looks exactly like a backup, right up until it is needed. The
     * destination is unique to this run, so removing it cannot discard anything else.
     *
     * @param string $outputPath backup file the command writes
     * @param string $command command to execute
     * @param string|null $workingPath directory to run the command from
     * @return void
     */
    protected function produceBackup(string $outputPath, string $command, string $workingPath = null) : void
    {
        try
        {
            $this->executeCommand($command, $workingPath);
        }
        catch (\Throwable $e)
        {
            $this->removeIncomplete($outputPath);

            throw $e;
        }

        if ($this->option('dry-run'))
        {
            return;
        }

        $this->chmod($outputPath);
    }

    protected function removeIncomplete(string $path) : void
    {
        if (!File::exists($path))
        {
            return;
        }

        $this->log(
            'warning',
            "Removing incomplete backup file [{$path}]",
            "Removing incomplete backup file",
            compact('path')
        );

        File::delete($path);
    }

    /**
     * Wrap a command containing a pipe so that a failure anywhere in the pipeline is
     * reported - a plain shell returns the exit status of the last command only, which
     * hides a failed mysqldump behind a gzip that compressed the partial output quite
     * happily and exited 0
     *
     * @param string $command command to run as a pipeline
     * @return string command to execute
     */
    protected function pipeline(string $command) : string
    {
        $shell = config('backup.shell');

        if (empty($shell))
        {
            return $command;
        }

        return "{$shell} -o pipefail -c " . escapeshellarg($command);
    }

    protected function chmod($path, $mode = 0660)
    {
    	if (!File::exists($path))
	    {
	    	$this->log('warning', "Path does not exist when changing permissions [{$path}]", "Path does not exist when changing permissions", compact('path'));
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

    // Scheduling is not used - Laravel Zero's scheduler cannot run these commands at
    // all, and cron drives them directly instead. See the readme.
    //
    //    /**
    //     * @return int offset in hours to execute this command on schedule
    //     */
    //    abstract protected function scheduleOffset() : int;
    //
    //    protected function getScheduleTime() : string
    //    {
    //        $scheduleStart = (int) config('backup.schedule_start');
    //        $offset = $this->scheduleOffset();
    //
    //        // wrap around midnight - an hour past 23 is not a valid cron expression
    //        return sprintf("%d:00", ($scheduleStart + $offset) % 24);
    //    }

	protected function human_filesize($bytes, $dec = 2)
	{
	    $size   = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
	    $factor = floor((strlen($bytes) - 1) / 3);

	    return sprintf("%.{$dec}f", $bytes / pow(1024, $factor)) . " " . @$size[$factor];
	}

}

