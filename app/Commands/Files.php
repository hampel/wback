<?php

namespace App\Commands;

use Illuminate\Console\Command;
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
                                {source?}
                                {--a|all : Process all sources}
                                {--d|dry-run : Do everything except the actual backup}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup files';


    protected function handleSource($source, $name)
    {
        if (!isset($source['files']) || empty($source['files']))
        {
            $this->log('notice', "No files source specified for {$name}");
            return Command::FAILURE;
        }

        if (!isset($source['destination']) || empty($source['destination']))
        {
            $this->log('error', "No destination specified for {$name}");
            return Command::FAILURE;
        }

        if (!File::isDirectory($source['files']))
        {
            $this->log(
                'error',
                "File source [{$source['files']}] does not exist for {$name}",
                "File source does not exist for {$name}",
                ['source' => $source['files']]
            );
            return Command::FAILURE;
        }

        return $this->backupFiles($source, $name);
    }

    protected function backupFiles($source, $name)
    {
        $files = $source['files'];

        $destination = $this->getDestination($source, $name,'files', '.zip');

        $zip = config('backup.zip_path');

        if ($this->output->isVerbose())
        {
            $verbosity = ' --verbose';
        }
        elseif ($this->output->isQuiet())
        {
            $verbosity = ' --quiet';
        }
        else
        {
            $verbosity = '';
        }

        $outputPath = Storage::disk()->path($destination);
        $exclude = $this->generateExcludes($source['exclude'] ?? []);

        $cmd = "cd {$files} && {$zip} -9{$verbosity} --recurse-paths --symlinks {$outputPath} .{$exclude}";

        $this->log(
            'notice',
            "Backing up files from [{$files}] to [{$destination}]",
            "Backing up files",
            compact('files', 'destination', 'cmd')
        );

        $this->executeCommand($cmd);
        $this->chmod($outputPath);
    }

    protected function generateExcludes(array $excludes)
    {
        $ex = collect($excludes)->transform(function ($value, $key) {
            return preg_replace('/[\*]/', '\*', $value);
        })->implode(' ');

        return empty($ex) ? '' : " --exclude {$ex}";
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

}
