<?php

namespace App\Commands;

use App\Support\BackupLock;
use App\Support\LogsToConsole;
use App\Support\ReadsCommandOutput;
use App\Support\SiteInventory;
use App\Support\SlackSummary;
use Hampel\ConsoleReport\RendersChecks;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Yosymfony\Toml\Exception\ParseException;

/**
 * Check that this machine can actually do what the configuration says
 *
 * Everything here exercises the real thing rather than describing it: the binaries are
 * run, the databases are connected to, the remotes are listed and the lock is taken. It
 * is meant to be the last step of provisioning a server, and the first thing to run when
 * a backup has gone quiet.
 */
class Validate extends Command
{
    use LogsToConsole;
    use ReadsCommandOutput;
    use RendersChecks;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:validate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the binaries, paths, sites and remotes this is configured to use';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // see Config::handle() - console-report 2.0 needs to be told where to write
        $this->setReportOutput($this->getOutput());

        $this->section('Binaries');
        $this->checkBinaries();

        $this->section('Paths');
        $this->checkPaths();

        $this->section('Sites');
        $this->checkSites();

        $this->section('Remotes');
        $this->checkRemotes();

        $this->section('Logging');
        $this->checkLogging();

        $this->section('Summary');
        $this->checkSummary();

        $this->newLine();

        if ($this->checksFailed())
        {
            $this->error('Validation failed - the backups configured here will not all work');
            return Command::FAILURE;
        }

        if ($this->checksWarned())
        {
            $this->comment('Validated, with warnings');
            return Command::SUCCESS;
        }

        $this->info('Everything checks out');

        return Command::SUCCESS;
    }

    /**
     * Run one of the checks, reporting a command that could not be started at all as
     * a failed check rather than letting it end the report
     *
     * Everything here runs an external command to find out whether it works, so one
     * that will not start is this command's subject matter and not an accident that
     * should stop it. On ap1 an unreadable working directory threw at the very first
     * binary and the run ended there - the paths, sites, remotes, logging and summary
     * were never looked at, by the one command whose whole job is to look at them.
     *
     * @param string $label check to report the failure against
     * @param string $command command to run
     * @param int $timeout seconds to allow it
     * @return mixed the process result, or null if it could not be started
     */
    protected function runCheck(string $label, string $command, int $timeout = 60)
    {
        try
        {
            return Process::timeout($timeout)->run($command);
        }
        catch (\Throwable $e)
        {
            // the console half of this is the check row below, so this is the one place
            // in the app that writes to the log directly rather than through log().
            // It does need writing: the whole message is what made this diagnosable on
            // ap1 after the fact, and a check row has room for one line of it
            Log::error("Check command could not be started", [
                'check' => $label,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            $this->checkFail($label, $this->startFailure($e));

            return null;
        }
    }

    /**
     * The one line worth showing from a command that would not start
     *
     * Symfony puts the useful part last. The first line only says the command failed,
     * which the check's own label has already said, while the cause and the working
     * directory - and on ap1 the working directory was the whole of what was wrong -
     * are several lines further down.
     *
     * @param \Throwable $e the failure
     * @return string one line, cause first
     */
    protected function startFailure(\Throwable $e) : string
    {
        $message = $e->getMessage();

        $reason = preg_match('/^Error: (.+)$/m', $message, $matches)
            ? trim($matches[1])
            : $this->firstLine($message);

        return preg_match('/^Working directory: (.+)$/m', $message, $matches)
            ? "{$reason} (working directory: " . trim($matches[1]) . ")"
            : $reason;
    }

    /**
     * Run each configured binary, which proves it exists, that it runs, and that a
     * setting carrying options of its own still resolves to something executable
     */
    protected function checkBinaries() : void
    {
        $binaries = [
            'mysqldump' => config('backup.mysql.dump_binary'),
            'gzip' => config('backup.gzip_binary'),
            'zip' => config('backup.zip_binary'),
            'rclone' => config('backup.rclone.binary'),
            'shell' => config('backup.shell'),
        ];

        foreach ($binaries as $name => $binary)
        {
            if (empty($binary))
            {
                $this->checkSkip($name, $name === 'shell'
                    ? 'not configured - pipelines will run under the system shell'
                    : 'not configured');
                continue;
            }

            $result = $this->runCheck($name, "{$binary} --version", 10);

            if ($result === null)
            {
                continue;
            }

            if (!$result->successful())
            {
                $this->checkFail($name, $binary . ' - ' . $this->firstLine($result->errorOutput() ?: $result->output()));
                continue;
            }

            $this->checkOk($name, $this->versionFrom($result->output()));
        }
    }

    protected function checkPaths() : void
    {
        $inventory = app(SiteInventory::class);

        $inventory->exists()
            ? $this->checkOk('sites file', $inventory->path())
            : $this->checkFail('sites file', 'not found at ' . $inventory->path());

        $destination = Storage::disk('backup')->path('');

        if (!File::isDirectory($destination))
        {
            $this->checkFail('backup destination', "does not exist: {$destination}");
        }
        elseif (!File::isWritable($destination))
        {
            $this->checkFail('backup destination', "not writable: {$destination}");
        }
        else
        {
            $free = @disk_free_space($destination);

            $this->checkOk('backup destination', $destination
                . ($free === false ? '' : ' - ' . $this->human_filesize($free) . ' free'));
        }

        $this->checkLock();

        $this->checkOk('timezone', config('app.timezone'));
        $this->checkOk('storage path', storage_path());
    }

    protected function checkLock() : void
    {
        $lock = app(BackupLock::class);

        try
        {
            if (!$lock->acquire($this->getName()))
            {
                $this->checkWarn('lock file', 'held by another run [' . $lock->holder() . ']');
                return;
            }
        }
        catch (\RuntimeException $e)
        {
            $this->checkFail('lock file', $e->getMessage());
            return;
        }

        $lock->release();

        $this->checkOk('lock file', $lock->path());
    }

    protected function checkSites() : void
    {
        $inventory = app(SiteInventory::class);

        try
        {
            $sites = $inventory->all();
        }
        catch (ParseException $e)
        {
            $this->checkFail('sites file', $e->getMessage());
            return;
        }

        if (empty($sites))
        {
            $this->checkFail('sites', 'none configured at ' . $inventory->path());
            return;
        }

        foreach ($sites as $name => $site)
        {
            if (empty($site['domain']))
            {
                $this->checkFail($name, 'no domain specified');
                continue;
            }

            $this->checkOk($name, $site['domain']);

            $this->checkSiteFiles($site, $name);
            $this->checkSiteDatabase($site, $name);
        }
    }

    protected function checkSiteFiles(array $site, string $name) : void
    {
        $source = $site['files'] ?? Storage::disk('files')->path($site['domain']);

        if (empty($source))
        {
            $this->checkSkip("{$name} files", 'no file backup configured');
            return;
        }

        if (!File::isDirectory($source))
        {
            $this->checkFail("{$name} files", "source not found: {$source}");
            return;
        }

        // what validate warns about mirrors what the stage refuses, which is how the
        // sync branch below is derived too - and files refuses an empty source
        // unconditionally, there being no files_allow_empty to weigh
        if (File::isEmptyDirectory($source))
        {
            $this->checkWarn("{$name} files", "source is empty, so backing it up would fail: {$source}"
                . " - set files = '' if this site has nothing to back up");
        }
        else
        {
            $this->checkOk("{$name} files", $source);
        }

        $sync = $site['sync'] ?? [];
        $sync = is_array($sync) ? $sync : [$sync];

        foreach ($sync as $path)
        {
            $syncPath = $source . DIRECTORY_SEPARATOR . $path;

            if (!File::isDirectory($syncPath))
            {
                $this->checkFail("{$name} sync", "path not found: {$syncPath}");
            }
            elseif (File::isEmptyDirectory($syncPath) && !config('backup.rclone.sync_allow_empty'))
            {
                $this->checkWarn("{$name} sync", "path is empty, so syncing it would be refused: {$syncPath}");
            }
            else
            {
                $this->checkOk("{$name} sync", $syncPath);
            }
        }
    }

    /**
     * Dump the schema and throw it away, which exercises the same binary, credentials
     * and user as the backup itself without moving any data
     */
    protected function checkSiteDatabase(array $site, string $name) : void
    {
        $database = $site['database'] ?? $name;

        if (empty($database))
        {
            $this->checkSkip("{$name} database", 'no database backup configured');
            return;
        }

        $mysqldump = config('backup.mysql.dump_binary');
        $hostname = isset($site['hostname']) ? " -h" . escapeshellarg($site['hostname']) : '';
        $port = isset($site['port']) ? " -P" . escapeshellarg((string) $site['port']) : '';

        $options = $site['options'] ?? config('backup.mysql.options');
        $options = empty($options) ? '' : " {$options}";

        $cmd = "{$mysqldump} --no-data --skip-lock-tables{$hostname}{$port}{$options} "
            . escapeshellarg($database) . " > /dev/null";

        $result = $this->runCheck("{$name} database", $cmd);

        if ($result === null)
        {
            return;
        }

        $result->successful()
            ? $this->checkOk("{$name} database", $database)
            : $this->checkFail("{$name} database", $database . ' - ' . $this->firstLine($result->errorOutput()));
    }

    protected function checkRemotes() : void
    {
        $remotes = [
            'cloud remote' => config('backup.rclone.cloud_remote'),
            'sync remote' => config('backup.rclone.sync_remote'),
        ];

        foreach ($remotes as $name => $remote)
        {
            if (empty($remote))
            {
                $this->checkSkip($name, 'not configured');
                continue;
            }

            $rclone = config('backup.rclone.binary');

            $result = $this->runCheck($name, "{$rclone} lsd " . escapeshellarg($remote));

            if ($result === null)
            {
                continue;
            }

            if ($result->successful())
            {
                $this->checkOk($name, $remote);
                continue;
            }

            // rclone reports a missing directory separately from a remote it cannot
            // reach at all, and a path that does not exist yet is normal before the
            // first upload
            $result->exitCode() === 3
                ? $this->checkWarn($name, "{$remote} - does not exist yet, it will be created on the first transfer")
                : $this->checkFail($name, $remote . ' - ' . $this->firstLine($result->errorOutput()));
        }
    }

    protected function checkLogging() : void
    {
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        foreach ($levels as $level)
        {
            $this->log($level, "Validation test message [{$level}]");
        }

        $channel = config('logging.default');

        $this->checkOk('log channel', $channel);

        if ($channel === 'stack')
        {
            $channels = implode(',', config('logging.channels.stack.channels'));

            $channels === 'null'
                ? $this->checkWarn('log stack', 'the null channel discards everything - set LOG_STACK or LOG_CHANNEL')
                : $this->checkOk('log stack', $channels);
        }

        if (in_array($channel, ['single', 'daily']))
        {
            $this->checkOk('log path', config("logging.channels.{$channel}.path"));
        }

        // worth reading back on a new installation: it is what tells one machine's
        // alerts from another's when they all report to the same place
        $hostname = config('logging.hostname');

        $hostname
            ? $this->checkOk('log hostname', $hostname)
            : $this->checkSkip('log hostname', 'records are not stamped with a hostname - set LOG_HOSTNAME');

        $this->line('  A message was written at every level - check that your logs received them');
    }

    /**
     * Post the test message, because a webhook that has stopped working says nothing
     * about it at this end - the summary simply never arrives, which looks the same as
     * a backup that never ran
     */
    protected function checkSummary() : void
    {
        $summary = app(SlackSummary::class);

        if (!$summary->isConfigured())
        {
            $this->checkSkip('run summary', 'nothing is sent - set BACKUP_SUMMARY_SLACK_WEBHOOK');
            return;
        }

        try
        {
            $summary->sendTest();
        }
        catch (\Throwable $e)
        {
            $this->checkFail('run summary', $this->firstLine($e->getMessage()));
            return;
        }

        $this->checkOk('run summary', 'test message delivered, sent on ' . config('backup.summary.notify'));
    }

    /**
     * @return string the first line that looks like it carries a version number, since
     *                zip leads with a copyright notice
     */
    protected function versionFrom(string $output) : string
    {
        foreach (explode("\n", $output) as $line)
        {
            if (preg_match('/\d+\.\d+/', $line))
            {
                return trim($line);
            }
        }

        return $this->firstLine($output);
    }

}
