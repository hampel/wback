<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

class Clean extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clean
                                {site?}
                                {--a|all : Process all sites}
                                {--d|dry-run : Do everything except the actual clean}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old backup files';

    protected function handleSite(array $site, string $name) : void
    {
        if (!empty($site['files']))
        {
            $this->clean($site, $name, 'files');
        }
        else
        {
            $this->log('notice', "No files path specified for {$name}");
        }

        if (!empty($site['database']))
        {
            $this->clean($site, $name, 'database');
        }
        else
        {
            $this->log('notice', "No database source specified for {$name}");
        }
    }

    protected function clean(array $site, string $name, string $type) : void
    {
        $path = $this->getDestinationPath($site, $name, $type, false);

        $this->log(
            'info',
            "Cleaning up old backups from [{$path}]",
            "Cleaning up old backups",
            compact('path')
        );

        $cutoff = Carbon::now()->subDays(config('backup.keeponly_days', 7))->timestamp;

        collect(Storage::disk('backup')->allFiles($path))
            ->reject(function ($path) use ($cutoff) {
                return Storage::disk('backup')->lastModified($path) > $cutoff;
            })
            ->each(function ($path) {
                $this->deleteFile($path);
            });
    }

    protected function deleteFile(string $path) : void
    {
        if ($this->option('dry-run'))
        {
            $this->log(
                'debug',
                "[Dry run] Deleting [{$path}]",
                "[Dry run] Deleting file",
                compact('path')
            );
        }
        else
        {
            $this->log(
                'debug',
                "Deleting old backup file [{$path}]",
                "Deleting old backup file",
                compact('path')
            );

            Storage::disk('backup')->delete($path);
        }
    }

    /**
     * @return int offset (in hours) to run this command daily based on universal start time
     */
    protected function scheduleOffset() : int
    {
        return 4;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class, ['--quiet', '--all'])->dailyAt($this->getScheduleTime());
    }
}
