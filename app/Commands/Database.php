<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;

class Database extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'database
                                {source?}
                                {--a|all : Process all sources}
                                {--d|dry-run : Do everything except the actual backup}
                            ';

    protected function configure()
    {
        $this->setAliases([
            'db',
        ]);

        parent::configure();
    }

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup databases';

    protected function handleSource($source, $name)
    {
        if (!isset($source['database']) || empty($source['database']))
        {
            $this->log('notice', "No database source specified for {$name}");
            return;
        }

        if (!isset($source['destination']) || empty($source['destination']))
        {
            $this->log('error', "No destination specified for {$name}");
            return;
        }

        return $this->backupDatabase($source, $name);
    }

    protected function backupDatabase($source, $name)
    {
        $database = $source['database'];

        $destination = $this->getDestination($source, $name,'database', '.sql.gz');

        $mysqldump = config('backup.mysql.dump_path');
        $verbosity = $this->output->isVerbose() ? ' --verbose' : '';
        $charset = $source['charset'] ?? config('backup.mysql.default_charset');
        $charset = empty($charset) ? '' : " --default-character-set={$charset}";
        $hostname = isset($source['hostname']) ? " -h{$source['hostname']}" : '';
        $gzip = config('backup.gzip_path');
        $outputPath = Storage::disk()->path($destination);

        $cmd = "{$mysqldump} --hex-blob --opt{$verbosity}{$charset}{$hostname} {$database} | {$gzip} -c -f > {$outputPath}";

        $this->log(
            'notice',
            "Backing up database [{$database}] to [{$destination}]",
            "Backing up database",
            compact('database', 'destination', 'cmd')
        );

        $this->executeCommand($cmd);
        $this->chmod($outputPath);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
