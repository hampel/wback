<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Support\Collection;
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
        $this->clean($site, $name, 'files');

        $database = $site['database'] ?? $name;
        if (!empty($database))
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

        $modified = collect(Storage::disk('backup')->allFiles($path))
            ->mapWithKeys(function ($path) {
                return [$path => Storage::disk('backup')->lastModified($path)];
            });

        $keep = $this->protectedDates($modified);

        $modified
            ->reject(function ($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            })
            ->reject(function ($timestamp) use ($keep) {
                return $keep->contains($this->backupDate($timestamp));
            })
            ->each(function ($timestamp, $path) {
                $this->deleteFile($path);
            });
    }

    /**
     * The days whose backups are kept whatever their age
     *
     * Age on its own eventually leaves nothing at all: a run of failures lasting longer
     * than the retention period expires the last good backups along with the rest. The
     * most recent days are held back from that, counted in days rather than files so
     * that several snapshots taken in one afternoon are one day of cover, not several.
     *
     * @param Collection $modified backup file path => modification timestamp
     * @return Collection dates in Y-m-d form, newest first
     */
    protected function protectedDates(Collection $modified) : Collection
    {
        $keep = (int) config('backup.keepleast_days');

        if ($keep < 1)
        {
            return collect();
        }

        return $modified->map(function ($timestamp) {
                return $this->backupDate($timestamp);
            })
            ->unique()
            ->sortDesc()
            ->take($keep)
            ->values();
    }

    protected function backupDate(int $timestamp) : string
    {
        return Carbon::createFromTimestamp($timestamp, config('app.timezone'))->toDateString();
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

    // Scheduling is not used - Laravel Zero's scheduler cannot run these commands
    // at all, and cron drives them directly instead. See the readme.
    //
    ///**
    // * @return int offset (in hours) to run this command daily based on universal start time
    // */
    //protected function scheduleOffset() : int
    //{
    //    return 4;
    //}
    //
    ///**
    // * Define the command's schedule.
    // */
    //public function schedule(Schedule $schedule): void
    //{
    //    $schedule->command(static::class, ['--quiet', '--all'])->dailyAt($this->getScheduleTime());
    //}
}
