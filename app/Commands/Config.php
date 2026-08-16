<?php

namespace App\Commands;

use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Terminal;

/**
 * What this installation is actually configured to do
 *
 * The lines are rendered here rather than with $this->components->twoColumnDetail(),
 * whose EnsureRelativePaths mutator strips base_path() out of every value it is given
 * and cannot be turned off. For an "about" screen that is a tidy touch; for a settings
 * dump it is the one mutation you cannot afford, because which file is being loaded is
 * the entire question being asked. It rendered the environment file as `.env` and the
 * backup destination as `storage/backup` - both plausible enough as relative paths that
 * nothing looked wrong.
 */
class Config extends Command
{
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
        $sections = $this->option('only') ? $this->sections() : [];

        foreach ($this->settings() as $section => $settings)
        {
            if ($sections && !in_array($this->toSearchKeyword($section), $sections))
            {
                continue;
            }

            $this->newLine();
            $this->heading($section);

            foreach ($settings as $label => $value)
            {
                $this->detail($label, $value);
            }
        }

        $this->newLine();

        return Command::SUCCESS;
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
                'Timezone' => config('app.timezone'),
            ],

            'Backup' => [
                'Environment File' => $this->path($this->laravel->environmentFilePath()),
                'Sites Path' => $this->path(config('backup.sites_path')),
                // binaries are left as written: a bare name is found on the PATH, so it
                // is not relative to anything the way a path would be
                'MySQL Dump Binary' => config('backup.mysql.dump_binary'),
                'MySQL Default Charset' => config('backup.mysql.default_charset'),
                'MySQL Hex Blob' => config('backup.mysql.hexblob') ? 'true' : 'false',
                'MySQL Single Transaction' => config('backup.mysql.single_transaction') ? 'true' : 'false',
                'MySQL Extra Options' => $this->redacted(config('backup.mysql.options')),
                'MySQL Verify Dumps' => config('backup.mysql.verify') ? 'true' : 'false',
                'Pipeline Shell' => config('backup.shell'),
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
                'Keep Only Days' => config('backup.keeponly_days'),
                'Keep Least Days' => config('backup.keepleast_days'),
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
            ],
        ];
    }

    /**
     * A path as the reader needs to see it
     *
     * A relative path is reported along with what it resolves against, because every
     * consumer of these resolves against the process working directory - which under
     * cron is wherever the crontab last changed to. Printing it bare hides the very
     * thing that makes it ambiguous.
     *
     * @param string|null $value configured path
     * @return string
     */
    protected function path(?string $value) : string
    {
        if (empty($value))
        {
            return '<fg=yellow>not set</>';
        }

        if (str_starts_with($value, DIRECTORY_SEPARATOR))
        {
            return $value;
        }

        return $value . ' <fg=yellow>(relative to ' . getcwd() . ')</>';
    }

    /**
     * @param string|null $value setting that a working installation needs
     * @return string
     */
    protected function required(?string $value) : string
    {
        return empty($value) ? '<fg=yellow>not set</>' : $value;
    }

    /**
     * @param string|null $value setting that is empty in the ordinary case
     * @return string
     */
    protected function optional(?string $value) : string
    {
        return empty($value) ? '<fg=gray>none</>' : $value;
    }

    /**
     * Options as written, less anything that looks like a password
     *
     * These go straight to the binary, so an installation can put credentials in one -
     * and this output is what gets pasted into a support ticket. It covers the flag
     * spellings people actually use rather than every possible one: credentials belong
     * in a defaults file that the tool reads for itself, which is what the readme
     * assumes.
     *
     * @param string|null $value operator supplied options
     * @return string
     */
    protected function redacted(?string $value) : string
    {
        $value = (string) preg_replace(
            ['/(--[\w-]*(?:pass|secret|token)[\w-]*[= ])\S+/i', '/(^|\s)(-p)\S+/'],
            ['${1}<fg=yellow>redacted</>', '${1}${2}<fg=yellow>redacted</>'],
            (string) $value
        );

        return $this->optional($value);
    }

    /**
     * One dotted line, or two when the value will not fit beside its label
     *
     * Wrapping rather than truncating: a path is worth less than nothing cut off, since
     * it still looks like a path and is not one.
     *
     * @param string $label setting name
     * @param string $value setting value, empty for a heading
     * @return void
     */
    protected function detail(string $label, string $value) : void
    {
        $width = min((new Terminal)->getWidth(), 150);

        // two margin columns, a space either side of the leader, and - for a heading,
        // whose value is empty - no trailing space to strip later
        $spacing = $value === '' ? 3 : 4;
        $room = $width - 2 - $spacing - $this->length($label) - $this->length($value);

        if ($room < 2)
        {
            $this->line("  {$label}");

            if ($value !== '')
            {
                $this->line("    {$value}");
            }

            return;
        }

        $line = "  {$label} <fg=gray>" . str_repeat('.', $room) . '</>';

        $this->line($value === '' ? $line : "{$line} {$value}");
    }

    /**
     * @param string $section section name
     * @return void
     */
    protected function heading(string $section) : void
    {
        $this->detail("<fg=green;options=bold>{$section}</>", '');
    }

    /**
     * @return int the visible width of a string, ignoring the console's own markup
     */
    protected function length(string $value) : int
    {
        return mb_strlen((string) preg_replace('/<[^>]+>/', '', $value));
    }

    /**
     * @return array sections named by --only
     */
    protected function sections() : array
    {
        return collect(explode(',', $this->option('only') ?? ''))
            ->filter()
            ->map(fn ($only) => $this->toSearchKeyword($only))
            ->all();
    }

    /**
     * Format the given string for searching.
     *
     * @param  string  $value
     * @return string
     */
    protected function toSearchKeyword(string $value)
    {
        return (new Stringable($value))->lower()->snake()->value();
    }
}
