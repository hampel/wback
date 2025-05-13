<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

class Cloud extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloud
                                {site?}
                                {--f|force : Force run, regardless of last run time}
                                {--a|all : Process all sites}
                                {--d|dry-run : Simulate the cloud copy with no actual changes}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send backup files to cloud storage';

    protected function handleSite(array $site, string $name) : void
    {
        if (empty(config('backup.rclone_remote')))
        {
            throw new \RuntimeException("rclone remote cloud destination not specified in config");
        }

        if (empty($site['domain'])) {
            throw new \RuntimeException("No domain specified for {$name}");
        }

        $this->backupCloud($site, $name);
    }

    protected function backupCloud(array $site, string $name) : void
    {
        $path = $site['domain']; // backup root

        if (!Storage::disk('backup')->exists($path))
        {
            $this->log('notice', "Backup path [{$path}] does not exist for {$name}");
            return;
        }

        $sourcePath = Storage::disk('backup')->path($path);
        $remotePath = rtrim(config('backup.rclone_remote'), '/') . '/' . $path;
        $verbosity = $this->getVerbosity();
        $dryrun = $this->option('dry-run') ? ' --dry-run' : '';
        $cmd = "rclone{$verbosity}{$dryrun} --progress copy {$sourcePath} {$remotePath}";

        $this->log(
            'notice',
            "Sending backup files from [{$sourcePath}] to [{$remotePath}]",
            "Sending backup files to cloud storage",
            ['source' => $sourcePath, 'remote' => $remotePath]
        );

        // over-ride the dry-run option because we have a --dry-run option for rclone
        $this->executeCommand($cmd, null, true);
    }

    /**
     * @return int offset (in hours) to run this command daily based on universal start time
     */
    protected function scheduleOffset() : int
    {
        return 2;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class, ['--quiet', '--all'])->dailyAt($this->getScheduleTime());
    }
}
