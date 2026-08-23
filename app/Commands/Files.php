<?php

namespace App\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class Files extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files
                                {site?}
                                {--a|all : Process all sites}
                                {--d|dry-run : Do everything except the actual backup}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup files';

    protected function handleSite(array $site, string $name) : void
    {
        $files = $site['files'] ?? Storage::disk('files')->path($site['domain']);
        if (empty($files))
        {
            $this->log('notice', "No files source specified for {$name}");
            return;
        }

        if (!File::isDirectory($files))
        {
            throw new \RuntimeException("Source path [{$files}] not found for {$name}");
        }

        // zip reports an empty directory as "Nothing to do!" and exit code 12, which
        // describes zip's predicament rather than the site's. An unexpectedly empty
        // docroot is worth failing over - it is what a half-finished migration looks
        // like - but it should be recognisable as that, and a site with deliberately
        // nothing to archive says so with files = '' rather than being discovered.
        if (File::isEmptyDirectory($files))
        {
            throw new \RuntimeException(
                "Source path [{$files}] is empty for {$name}"
                . " - set files = '' for this site if it has nothing to back up"
            );
        }

        $this->backupFiles($site, $name, $files);
    }

    protected function backupFiles(array $site, string $name, string $source) : void
    {
        $destination = $this->getDestinationFile($site, $name,'files', '.zip');

        $zip = config('backup.zip_binary');

        $verbosity = $this->getVerbosity();

        $outputPath = Storage::disk('backup')->path($destination);
        $exclude = $this->generateExcludes($site['exclude'] ?? []);

        $cmd = "{$zip} -9{$verbosity} --recurse-paths --symlinks " . escapeshellarg($outputPath) . " .{$exclude}";

        $this->log(
            'info',
            "Backing up files from [{$source}] to [{$destination}]",
            "Backing up files",
            compact('source', 'destination')
        );

        $this->produceBackup($outputPath, $cmd, $source);
    }

    protected function generateExcludes(array $excludes) : string
    {
        // quoting keeps the shell away from the wildcards, leaving zip to match them
        $ex = collect($excludes)->transform(function ($value, $key) {
            return escapeshellarg($value);
        })->implode(' ');

        return empty($ex) ? '' : " --exclude {$ex}";
    }

    // Scheduling is not used - Laravel Zero's scheduler cannot run these commands
    // at all, and cron drives them directly instead. See the readme.
    //
    ///**
    // * @return int offset (in hours) to run this command daily based on universal start time
    // */
    //protected function scheduleOffset() : int
    //{
    //    return 1;
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
