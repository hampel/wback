<?php

namespace App\Commands;

use App\Support\BackupLock;
use App\Support\LogsToConsole;
use App\Support\SiteInventory;
use Illuminate\Support\Facades\File;
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
     * @var bool something is wrong
     */
    protected $failed = false;

    /**
     * @var bool something is worth knowing about
     */
    protected $warned = false;

    /**
     * Execute the console command.
     */
    public function handle()
    {
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

        $this->newLine();

        if ($this->failed)
        {
            $this->error('Validation failed - the backups configured here will not all work');
            return Command::FAILURE;
        }

        if ($this->warned)
        {
            $this->comment('Validated, with warnings');
            return Command::SUCCESS;
        }

        $this->info('Everything checks out');

        return Command::SUCCESS;
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

            $result = Process::timeout(10)->run("{$binary} --version");

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

        $this->checkOk("{$name} files", $source);

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

        $options = $site['options'] ?? config('backup.mysql.options');
        $options = empty($options) ? '' : " {$options}";

        $cmd = "{$mysqldump} --no-data --skip-lock-tables{$hostname}{$options} "
            . escapeshellarg($database) . " > /dev/null";

        $result = Process::timeout(60)->run($cmd);

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

            $result = Process::timeout(60)->run("{$rclone} lsd " . escapeshellarg($remote));

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

        $this->line('  A message was written at every level - check that your logs received them');
    }

    protected function checkOk(string $label, string $detail = '') : void
    {
        $this->result('<info>[ ok ]</info>', $label, $detail);
    }

    protected function checkWarn(string $label, string $detail = '') : void
    {
        $this->warned = true;

        $this->result('<comment>[warn]</comment>', $label, $detail);
    }

    protected function checkFail(string $label, string $detail = '') : void
    {
        $this->failed = true;

        $this->result('<error>[fail]</error>', $label, $detail);
    }

    protected function checkSkip(string $label, string $detail = '') : void
    {
        $this->result('[    ]', $label, $detail);
    }

    protected function result(string $marker, string $label, string $detail) : void
    {
        $this->line(sprintf('  %s %-24s %s', $marker, $label, $detail));
    }

    protected function firstLine(string $output) : string
    {
        foreach (explode("\n", $output) as $line)
        {
            if (trim($line) !== '')
            {
                return trim($line);
            }
        }

        return '(no output)';
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

	protected function human_filesize($bytes, $dec = 2)
	{
	    $size   = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
	    $factor = floor((strlen($bytes) - 1) / 3);

	    return sprintf("%.{$dec}f", $bytes / pow(1024, $factor)) . " " . @$size[$factor];
	}
}
