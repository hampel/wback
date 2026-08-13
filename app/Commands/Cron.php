<?php

namespace App\Commands;

use App\Support\LocksBackups;
use App\Support\LogsToConsole;
use LaravelZero\Framework\Commands\Command;

class Cron extends Command
{
    use LocksBackups, LogsToConsole;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron
                                {--d|dry-run : Do everything except the actual backup}
                                {--no-database : Skip the database backups}
                                {--no-files : Skip the file backups}
                                {--no-cloud : Skip copying backups to cloud storage}
                                {--no-sync : Skip syncing live directories to cloud storage}
                                {--no-clean : Skip expiring old backups}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run every backup in turn, for a single cron entry';

    /**
     * The backup commands, in the order they depend on each other
     *
     * Everything is backed up before any of it is sent away, and nothing is expired
     * until it has been sent. Each stage starts when the one before it has actually
     * finished, rather than when a crontab guessed it would be finished.
     *
     * @var array
     */
    protected $stages = [
        'database',
        'files',
        'cloud',
        'sync',
        'clean',
    ];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if ($this->option('dry-run'))
        {
            $this->comment("Dry run only - no action will be taken");
        }

        // one lock for the whole run, so tonight's backups cannot start over the top of
        // last night's - the stages themselves see that it is held and leave it alone
        if (!$this->acquireLock())
        {
            return Command::FAILURE;
        }

        try
        {
            return $this->runStages();
        }
        finally
        {
            $this->releaseLock();
        }
    }

    /**
     * @return int exit code, failure if any stage failed
     */
    protected function runStages() : int
    {
        $arguments = ['--all' => true];

        if ($this->option('dry-run'))
        {
            $arguments['--dry-run'] = true;
        }

        $failed = false;

        foreach ($this->stages as $stage)
        {
            // a stage nobody has configured yet - cloud storage on a server still being
            // built, say - would otherwise fail every site for want of a remote
            if ($this->option("no-{$stage}"))
            {
                $this->log(
                    'notice',
                    "Skipping [{$stage}]",
                    "Skipping backup stage",
                    ['stage' => $stage]
                );

                continue;
            }

            $this->section($stage);

            // a stage that fails does not stop the ones after it: a database that will
            // not dump should not cost us the file backups as well
            if ($this->call($stage, $arguments) !== Command::SUCCESS)
            {
                $failed = true;

                $this->log(
                    'error',
                    "Backup stage [{$stage}] failed",
                    "Backup stage failed",
                    ['stage' => $stage]
                );
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
