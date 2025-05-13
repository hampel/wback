<?php

namespace App\Commands;

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
                                {--f|force : Force run, regardless of last run time}
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

        if (empty(config('backup.rclone_remote')))
        {
            throw new \RuntimeException("rclone remote cloud destination not specified in config");
        }

        if (!empty($site['files']))
        {
            $source = $site['files'];
        }
        else
        {
            if (empty($site['domain'])) {
                throw new \RuntimeException("No domain specified for {$name}");
            }

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
            $this->log('notice', "Sync path [{$syncPath}] does not exist for {$name}");
            return;
        }

        $remotePath = rtrim(config('backup.rclone_remote'), '/') . "/{$site['domain']}/sync/{$path}";
        $verbosity = $this->getVerbosity();
        $dryrun = $this->option('dry-run') ? ' --dry-run' : '';
        $cmd = "rclone{$verbosity}{$dryrun} --progress sync {$syncPath} {$remotePath}";

        $this->log(
            'notice',
            "Syncing files from [{$syncPath}] to [{$remotePath}]",
            "Syncing files to cloud storage",
            ['source' => $syncPath, 'remote' => $remotePath]
        );

        // over-ride the dry-run option because we have a --dry-run option for rclone
        $this->executeCommand($cmd, null, true);
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
