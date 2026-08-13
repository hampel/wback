<?php

namespace App\Commands;

use Illuminate\Support\Facades\File;
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
        $charset = empty($charset) ? '' : " --default-character-set=" . escapeshellarg($charset);
        $hexblob = config('backup.mysql.hexblob') ? ' --hex-blob' : '';

        // dump from a snapshot rather than locking every table for the duration
        $snapshot = $site['single_transaction'] ?? config('backup.mysql.single_transaction');
        $snapshot = $snapshot ? ' --single-transaction' : '';

        $hostname = isset($site['hostname']) ? " -h" . escapeshellarg($site['hostname']) : '';

        // operator supplied, so inserted as written - see the note in .env.example
        $options = $site['options'] ?? config('backup.mysql.options');
        $options = empty($options) ? '' : " {$options}";

        $gzip = config('backup.gzip_binary');
        $outputPath = Storage::disk('backup')->path($destination);

        $cmd = "{$mysqldump} --opt{$verbosity}{$charset}{$hexblob}{$snapshot}{$hostname}{$options} "
            . escapeshellarg($database)
            . " | {$gzip} -c -f > " . escapeshellarg($outputPath);

        $this->log(
            'info',
            "Backing up database [{$database}] to [{$destination}]",
            "Backing up database",
            compact('database', 'destination')
        );

        $this->produceBackup($outputPath, $this->pipeline($cmd));

        $this->verifyDump($site, $destination, $outputPath);
    }

    /**
     * Read the dump back, and check mysqldump got to the end of it
     *
     * Running the pipeline under pipefail catches a dump that reports failure. This
     * catches the one that does not: a mysqldump stopped partway - killed, timed out,
     * disconnected - leaves a perfectly valid gzip file holding half a database, and
     * nothing about the file itself says so. The marker mysqldump writes when it
     * finishes is the only thing that does.
     *
     * @param array $site site config from toml
     * @param string $destination backup file, relative to the backup disk
     * @param string $path backup file, absolute
     * @return void
     * @throws \RuntimeException if the dump is incomplete
     */
    protected function verifyDump(array $site, string $destination, string $path) : void
    {
        $verify = $site['verify'] ?? config('backup.mysql.verify');

        if ($this->option('dry-run') || !$verify || !File::exists($path))
        {
            return;
        }

        if (!$this->dumpIsComplete($path))
        {
            throw new \RuntimeException(
                "Dump [{$destination}] is incomplete - mysqldump did not finish writing it."
                . " The file has been kept so you can look at it"
            );
        }

        $this->log(
            'info',
            "Verified [{$destination}]",
            "Verified backup",
            compact('destination')
        );
    }

    /**
     * @param string $path backup file, absolute
     * @return bool whether the dump ends the way mysqldump ends one
     */
    protected function dumpIsComplete(string $path) : bool
    {
        $handle = @gzopen($path, 'rb');

        if ($handle === false)
        {
            return false;
        }

        $tail = '';

        while (!gzeof($handle))
        {
            $chunk = @gzread($handle, 65536);

            // corrupt, or truncated partway through the stream
            if ($chunk === false || $chunk === '')
            {
                break;
            }

            $tail = substr($tail . $chunk, -512);
        }

        gzclose($handle);

        return str_contains($tail, '-- Dump completed');
    }

    // Scheduling is not used - Laravel Zero's scheduler cannot run these commands
    // at all, and cron drives them directly instead. See the readme.
    //
    ///**
    // * @return int offset (in hours) to run this command daily based on universal start time
    // */
    //protected function scheduleOffset() : int
    //{
    //    return 0;
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
