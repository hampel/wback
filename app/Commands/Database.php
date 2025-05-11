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
        if (empty($site['database']))
        {
            $this->log('notice', "No database source specified for {$name}");
            return;
        }

        $this->backupDatabase($site, $name);
    }

    protected function backupDatabase(array $site, string $name) : void
    {
        $database = $site['database'];

        $destination = $this->getDestinationFile($site, $name,'database', '.sql.gz');

        $mysqldump = config('backup.mysql.dump_path');
        $verbosity = $this->output->isVerbose() ? ' --verbose' : '';
        $charset = $site['charset'] ?? config('backup.mysql.default_charset');
        $charset = empty($charset) ? '' : " --default-character-set={$charset}";
        $hexblob = config('backup.mysql.hexblob') ? ' --hex-blob' : '';
        $hostname = isset($site['hostname']) ? " -h{$site['hostname']}" : '';
        $gzip = config('backup.gzip_path');
        $outputPath = Storage::disk('backup')->path($destination);

        $cmd = "{$mysqldump} --opt{$verbosity}{$charset}{$hexblob}{$hostname} {$database} | {$gzip} -c -f > {$outputPath}";

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
