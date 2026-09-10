<?php

namespace App\Commands;

use Hampel\ConsoleReport\FormatsValues;
use Hampel\ConsoleReport\ReportsSettings;
use LaravelZero\Framework\Commands\Command;

/**
 * What this installation is actually configured to do
 *
 * The rendering comes from hampel/console-report rather than from
 * $this->components->twoColumnDetail(), whose EnsureRelativePaths mutator strips
 * base_path() out of every value it is given and cannot be turned off. For an "about"
 * screen that is a tidy touch; for a settings dump it is the one mutation you cannot
 * afford, because which file is being loaded is the entire question being asked. It
 * rendered the environment file as `.env` and the backup destination as
 * `storage/backup` - both plausible enough as relative paths that nothing looked wrong.
 *
 * Only the content is decided here: which settings a backup tool's operator needs to
 * see, and how each value should be reported.
 */
class Config extends Command
{
    use FormatsValues;
    use ReportsSettings;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:config {--only= : The section to display}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show application configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // console-report 2.0 writes to a Symfony OutputInterface it is handed rather than
        // calling line() on the command, so that it can serve consoles that are not
        // Laravel. getOutput() returns an Illuminate\Console\OutputStyle, which is one.
        $this->setReportOutput($this->getOutput());

        $this->reportSettings($this->settings(), $this->option('only'));

        return Command::SUCCESS;
    }

    /**
     * The environment file that was read, or where it was looked for
     *
     * Not environmentFilePath(), which answers with base_path().'/.env' whether or not
     * anything is there - and inside a compiled binary that names a file in the phar,
     * which has never been opened and cannot be. It reads exactly like a real answer,
     * which is the one thing this line must not do: it is where someone goes first when
     * a setting is not taking effect.
     *
     * bootstrap/app.php records the outcome of its own search, so when nothing was found
     * this can say where it looked - which is the whole of what a reader needs to fix it.
     *
     * @return string
     */
    protected function environmentFile() : string
    {
        $loaded = $this->laravel->bound('wback.env.loaded')
            ? $this->laravel->make('wback.env.loaded')
            : null;

        if ($loaded !== null)
        {
            return $this->path($loaded);
        }

        $candidates = $this->laravel->bound('wback.env.candidates')
            ? $this->laravel->make('wback.env.candidates')
            : [];

        return empty($candidates)
            ? $this->notSet()
            : '<fg=yellow>none found</> - looked in ' . implode(', ', $candidates);
    }

    /**
     * @return array<string, array<string, string>> settings to report, by section
     */
    protected function settings() : array
    {
        return [
            'Application' => [
                'Name' => config('app.name'),
                'Version' => $this->app->version(),
                'Laravel Version' => $this->app::VERSION,
                'PHP Version' => phpversion(),
                'Environment' => $this->laravel->environment(),
                'Debug Mode' => config('app.debug') ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF',
                'Timezone' => config('backup.timezone'),
            ],

            'Backup' => [
                'Environment File' => $this->environmentFile(),
                'Sites Path' => $this->path(config('backup.sites_path')),
                // binaries are left as written: a bare name is found on the PATH, so it
                // is not relative to anything the way a path would be
                'MySQL Dump Binary' => config('backup.mysql.dump_binary'),
                'MySQL Default Charset' => config('backup.mysql.default_charset'),
                'MySQL Hex Blob' => config('backup.mysql.hexblob') ? 'true' : 'false',
                'MySQL Single Transaction' => config('backup.mysql.single_transaction') ? 'true' : 'false',
                'MySQL Extra Options' => $this->redacted(config('backup.mysql.options')),
                'MySQL Verify Dumps' => config('backup.mysql.verify') ? 'true' : 'false',
                // empty is a legitimate setting here - pipelines then run under the
                // system shell - and an empty value would otherwise render as a heading
                'Pipeline Shell' => $this->optional(config('backup.shell')),
                'GZip Binary' => config('backup.gzip_binary'),
                'Zip Binary' => config('backup.zip_binary'),
                'rclone Binary' => config('backup.rclone.binary'),
                // a remote is "remote:bucket" - complete as written, with nothing for a
                // working directory to resolve
                'rclone Cloud Remote' => $this->required(config('backup.rclone.cloud_remote')),
                'rclone Sync Remote' => $this->required(config('backup.rclone.sync_remote')),
                'rclone Cloud Options' => $this->redacted(config('backup.rclone.cloud_options')),
                'rclone Sync Options' => $this->redacted(config('backup.rclone.sync_options')),
                'rclone Sync Allow Empty' => config('backup.rclone.sync_allow_empty') ? 'true' : 'false',
                'rclone Sync Backup Dir' => $this->optional(config('backup.rclone.sync_backup_dir')),
                // cast: these are integers in config, and the renderer is strict about
                // being handed a string
                'Keep Only Days' => (string) config('backup.keeponly_days'),
                'Keep Least Days' => (string) config('backup.keepleast_days'),
                // the fallback is a description of where the lock goes, not a path
                'Lock File' => config('backup.lock_file')
                    ? $this->path(config('backup.lock_file'))
                    : 'backup destination',
            ],

            'Filesystems' => [
                'Default' => config('filesystems.default'),
                'Storage Path' => $this->path(storage_path()),
                'Files Disk' => $this->path(config('filesystems.disks.files.root')),
                'Backup Disk' => $this->path(config('filesystems.disks.backup.root')),
            ],

            'Logging' => [
                'Default' => config('logging.default'),
                'Hostname Stamp' => $this->optional(config('logging.hostname')),
                'Stack Channels' => implode(',', config('logging.channels.stack.channels')),
                'Single Path' => $this->path(config('logging.channels.single.path')),
                'Single Level' => config('logging.channels.single.level'),
                // a webhook url is a credential: anyone holding it can post to the
                // channel, and this output is what gets pasted into a support ticket
                'Slack Webhook' => $this->secretStatus(config('logging.channels.slack.url')),
                'Slack Level' => config('logging.channels.slack.level'),
            ],

            'Summary' => [
                'Slack Webhook' => $this->secretStatus(config('backup.summary.slack_webhook')),
                'Notify' => config('backup.summary.notify'),
            ],
        ];
    }
}
