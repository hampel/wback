<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
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

        if (!File::isDirectory($source))
        {
            throw new \RuntimeException("Source path [{$source}] not found for {$name}");
        }

        $this->backupFiles($site, $name, $source);
    }

    protected function backupFiles(array $site, string $name, string $source) : void
    {
        $destination = $this->getDestinationFile($site, $name,'files', '.zip');

        $zip = config('backup.zip_path');

        $verbosity = $this->getVerbosity();

        $outputPath = Storage::disk('backup')->path($destination);
        $exclude = $this->generateExcludes($site['exclude'] ?? []);

        $cmd = "{$zip} -9{$verbosity} --recurse-paths --symlinks {$outputPath} .{$exclude}";

        $this->log(
            'notice',
            "Backing up files from [{$source}] to [{$destination}]",
            "Backing up files",
            compact('source', 'destination', 'cmd')
        );

        $this->executeCommand($cmd, $source);
        $this->chmod($outputPath);
    }

    protected function generateExcludes(array $excludes) : string
    {
        $ex = collect($excludes)->transform(function ($value, $key) {
            return preg_replace('/[\*]/', '\*', $value);
        })->implode(' ');

        return empty($ex) ? '' : " --exclude {$ex}";
    }

    /**
     * @return int offset (in hours) to run this command daily based on universal start time
     */
    protected function scheduleOffset() : int
    {
        return 1;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class, ['--quiet', '--all', 'files'])->dailyAt($this->getScheduleTime());
    }

}
