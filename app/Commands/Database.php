<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

class Database extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'database
                                {site?}
                                {--a|all : Process all sites}
                                {--d|dry-run : Do everything except the actual backup}
                            ';

//    protected function configure()
//    {
//        $this->setAliases([
//            'db',
//        ]);
//
//        parent::configure();
//    }

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup databases';

    protected function handleSite(array $site, string $name) : void
    {
        $database = $site['database'] ?? $name;
        if (empty($database))
        {
            $this->log('notice', "No database source specified for {$name}");
            return;
        }

        $this->backupDatabase($site, $name, $database);
    }

    protected function backupDatabase(array $site, string $name, string $database) : void
    {
        $destination = $this->getDestinationFile($site, $name,'database', '.sql.gz');

        $mysqldump = config('backup.mysql.dump_binary');
        $verbosity = $this->output->isVerbose() ? ' --verbose' : '';
        $charset = $site['charset'] ?? config('backup.mysql.default_charset');
        $charset = empty($charset) ? '' : " --default-character-set={$charset}";
        $hexblob = config('backup.mysql.hexblob') ? ' --hex-blob' : '';
        $hostname = isset($site['hostname']) ? " -h{$site['hostname']}" : '';
        $gzip = config('backup.gzip_binary');
        $outputPath = Storage::disk('backup')->path($destination);

        $cmd = "{$mysqldump} --opt{$verbosity}{$charset}{$hexblob}{$hostname} {$database} | {$gzip} -c -f > {$outputPath}";

        $this->log(
            'info',
            "Backing up database [{$database}] to [{$destination}]",
            "Backing up database",
            compact('database', 'destination')
        );

        $this->executeCommand($cmd);
        $this->chmod($outputPath);
    }

    /**
     * @return int offset (in hours) to run this command daily based on universal start time
     */
    protected function scheduleOffset() : int
    {
        return 0;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(static::class, ['--quiet', '--all'])->dailyAt($this->getScheduleTime());
    }
}
