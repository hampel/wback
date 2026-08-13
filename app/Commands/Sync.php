<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class Sync extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync
                                {site?}
                                {--a|all : Process all sites}
                                {--d|dry-run : Simulate the cloud sync with no actual changes}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync files to cloud storage';

    protected function handleSite(array $site, string $name) : void
    {
        if (empty($site['sync']))
        {
            $this->log('notice', "No sync config specified for {$name}");
            return;
        }

        if (empty(config('backup.rclone.sync_remote')))
        {
            throw new \RuntimeException("rclone remote sync destination not specified in config");
        }

        if (!empty($site['files']))
        {
            $source = $site['files'];
        }
        else
        {
            $source = Storage::disk('files')->path($site['domain']);
        }

        $sync = is_array($site['sync']) ? $site['sync'] : [$site['sync']];

        foreach ($sync as $path)
        {
            $this->backupSync($site, $name, $source, $path);
        }
    }

    protected function backupSync(array $site, string $name, string $source, string $path) : void
    {
        $syncPath = $source . DIRECTORY_SEPARATOR . $path;

        if (!File::isDirectory($syncPath))
        {
            $this->log('warning', "Sync path [{$syncPath}] does not exist for {$name}");
            return;
        }

        // sync makes the remote match the source, so an empty source empties the remote
        // copy - and an empty source is what an unmounted filesystem looks like
        if (File::isEmptyDirectory($syncPath) && !config('backup.rclone.sync_allow_empty'))
        {
            throw new \RuntimeException(
                "Sync path [{$syncPath}] is empty for {$name} - refusing to empty the remote copy."
                . " Set BACKUP_SYNC_ALLOW_EMPTY=true if the source really is empty"
            );
        }

        $rclone = config('backup.rclone.binary');
        $remotePath = rtrim(config('backup.rclone.sync_remote'), '/') . "/{$site['domain']}/sync/{$path}";
        $verbosity = $this->getVerbosity();
        $dryrun = $this->option('dry-run') ? ' --dry-run' : '';

        // operator supplied, so inserted as written
        $options = config('backup.rclone.sync_options');
        $options = empty($options) ? '' : " {$options}";

        $archive = $this->archiveOption($site, $path);

        $cmd = "{$rclone}{$verbosity}{$dryrun}{$options} --progress sync{$archive} "
            . escapeshellarg($syncPath) . ' ' . escapeshellarg($remotePath);

        $this->log(
            'info',
            "Syncing files from [{$syncPath}] to [{$remotePath}]",
            "Syncing files to cloud storage",
            ['source' => $syncPath, 'remote' => $remotePath]
        );

        // over-ride the dry-run option because we have a --dry-run option for rclone
        $this->executeCommand($cmd, null, true);
    }

    /**
     * Where sync should put the files it would otherwise destroy
     *
     * With this set, a sync that goes wrong - a source that lost its files, a path that
     * moved - costs storage rather than data, because everything sync replaces or
     * deletes is moved into a dated directory on the remote instead.
     *
     * @param array $site site config from toml
     * @param string $path sync path relative to the site file source
     * @return string --backup-dir option, or empty to let sync delete outright
     */
    protected function archiveOption(array $site, string $path) : string
    {
        $archive = config('backup.rclone.sync_backup_dir');

        if (empty($archive))
        {
            return '';
        }

        $date = Carbon::today(new \DateTimeZone(config('app.timezone')))->format('Ymd');

        $archivePath = rtrim(config('backup.rclone.sync_remote'), '/')
            . "/{$site['domain']}/{$archive}/{$date}/{$path}";

        return ' --backup-dir ' . escapeshellarg($archivePath);
    }

    /**
     * @return int offset (in hours) to run this command daily based on universal start time
     */
    protected function scheduleOffset() : int
    {
        return 3;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class, ['--quiet', '--all'])->dailyAt($this->getScheduleTime());
    }
}
