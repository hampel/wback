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
     * The most severe level anything in this application logs at.
     *
     * Every real failure is an ERROR record - a site that threw, a command that could
     * not start, a lock already held - and nothing anywhere logs at critical or above
     * except the sweep below, which writes one of each on purpose. So a Slack channel
     * thresholded above this can only ever catch that sweep: it looks configured, it
     * passes every check, and it stays silent on the night it was installed for.
     *
     * Raise this only when something actually starts logging higher.
     */
    protected const HIGHEST_LOGGED_LEVEL = 'error';

    /** @return string the constant above, for the test that keeps it honest */
    public static function highestLoggedLevel() : string
    {
        return self::HIGHEST_LOGGED_LEVEL;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:validate
                                {--unattended : Do not send the messages whose only proof is a person seeing them arrive}';

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

        $this->checkSection('Binaries');
        $this->checkBinaries();

        $this->checkSection('Paths');
        $this->checkPaths();

        $this->checkSection('Sites');
        $this->checkSites();

        $this->checkSection('Remotes');
        $this->checkRemotes();

        $this->checkSection('Logging');
        $this->checkLogging();

        $this->checkSection('Summary');
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
     * should stop it. In production an unreadable working directory threw at the very first
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
            // It does need writing: the whole message is what made this diagnosable
            // in production after the fact, and a check row has room for one line of it
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
     * directory - and in production the working directory was the whole of what was wrong -
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

        $this->checkOk('timezone', config('backup.timezone'));
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

        if (!$this->option('unattended'))
        {
            foreach ($levels as $level)
            {
                $this->log($level, "Validation test message [{$level}]");
            }
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

        $this->checkDelivery($levels);
    }

    /**
     * Say what the sweep just posted, because a count nobody was given is not one
     * anybody can check
     *
     * The sweep above is the only thing that proves the Slack threshold: the webhook URL
     * and LOG_SLACK_LEVEL are both unprovable from this end, and the records arriving
     * prove them at once. That only works if the operator knows how many to expect -
     * four is right for a level of `error`, three means the threshold is not what the
     * configuration says.
     *
     * @param array $levels every level the sweep writes at, lowest first
     */
    protected function checkDelivery(array $levels) : void
    {
        $channel = config('logging.default');

        $channels = $channel === 'stack'
            ? config('logging.channels.stack.channels')
            : [$channel];

        // only the slack channel is reported on: it is the one whose threshold cannot be
        // checked from here. Note that a driver test is the wrong way to BOUND the sweep
        // - papertrail is driver => monolog and would leave the machine either way - but
        // for saying what to go and look for, the channel is exactly the question
        $slack = array_values(array_filter(
            $channels,
            fn ($name) => config("logging.channels.{$name}.driver") === 'slack'
        ));

        if (empty($slack))
        {
            $this->checkSkip('log delivery', 'nothing in the log stack posts to slack');
            return;
        }

        foreach ($slack as $name)
        {
            if (!config("logging.channels.{$name}.url"))
            {
                $this->checkWarn($name . ' delivery', 'no webhook, so nothing was posted - set LOG_SLACK_WEBHOOK_URL');
                continue;
            }

            $threshold = config("logging.channels.{$name}.level");
            $index = array_search(strtolower((string) $threshold), $levels, true);

            if ($index === false)
            {
                // an unrecognised level is Monolog's to reject, not ours to guess at
                $this->checkWarn($name . ' delivery', "cannot tell what was posted - '{$threshold}' is not a log level");
                continue;
            }

            // deliberately BEFORE the --unattended return: a threshold set above
            // anything this application logs is a static fact about the configuration,
            // not something the sweep discovers, so a deploy gate should surface it
            // even on a run that posts nothing
            $ceiling = array_search(self::HIGHEST_LOGGED_LEVEL, $levels, true);

            if ($index > $ceiling)
            {
                $this->checkWarn(
                    $name . ' threshold',
                    "{$threshold} is above " . self::HIGHEST_LOGGED_LEVEL . ', which nothing here logs at'
                        . ' - the channel can only catch this command\'s own test message'
                        . ' - set LOG_SLACK_LEVEL=' . self::HIGHEST_LOGGED_LEVEL
                );
            }

            if ($this->option('unattended'))
            {
                $this->checkSkip($name . ' delivery', 'nothing was posted - --unattended');
                continue;
            }

            $posted = array_slice($levels, $index);

            // the run summary posts separately and may well share this webhook, in which
            // case the operator counts one more than the sweep sent. An arrival count
            // that does not say so is what made four look like a pass under a threshold
            // of error AND a threshold of critical
            $shared = config('backup.summary.slack_webhook')
                && config('backup.summary.slack_webhook') === config("logging.channels.{$name}.url");

            $this->checkOk(
                $name . ' delivery',
                'posted ' . count($posted) . ' ' . str('record')->plural(count($posted))
                    . ' at ' . $threshold . ' and above: ' . implode(', ', $posted)
                    . ($shared
                        ? ', plus the run summary test on the same webhook - expect ' . (count($posted) + 1)
                        : '')
                    . ' - check they arrived'
            );
        }
    }

    /**
     * Post the test message, because a webhook that has stopped working says nothing
     * about it at this end - the summary simply never arrives, which looks the same as
     * a backup that never ran
     *
     * Under --unattended it is skipped for the same reason the log sweep is: the message
     * is only worth sending when somebody is going to look at where it lands.
     */
    protected function checkSummary() : void
    {
        $summary = app(SlackSummary::class);

        if (!$summary->isConfigured())
        {
            $this->checkSkip('run summary', 'nothing is sent - set BACKUP_SUMMARY_SLACK_WEBHOOK');
            return;
        }

        // the same reasoning as the log sweep: the webhook is proved by the message
        // arriving, so there is no point spending one on a channel nobody is reading
        if ($this->option('unattended'))
        {
            $this->checkSkip('run summary', 'configured, but nothing was sent - --unattended');
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
