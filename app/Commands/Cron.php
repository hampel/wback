<?php

namespace App\Commands;

use App\Support\LocksBackups;
use App\Support\LogsToConsole;
use App\Support\RunSummary;
use App\Support\SlackSummary;
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

        $summary = app(RunSummary::class);

        $summary->start((bool) $this->option('dry-run'));

        // one lock for the whole run, so tonight's backups cannot start over the top of
        // last night's - the stages themselves see that it is held and leave it alone
        if (!$this->acquireLock())
        {
            // a backup that did not happen is the failure worth hearing about most, and
            // the one that otherwise leaves nothing behind but a single log line
            $summary->block((string) $this->lockFailure);

            $this->notify($summary);

            return Command::FAILURE;
        }

        try
        {
            $result = $this->runStages();
        }
        finally
        {
            $this->releaseLock();
        }

        // after the lock, not inside it: a Slack endpoint that has gone slow should not
        // hold tomorrow's run off
        $this->notify($summary);

        return $result;
    }

    /**
     * Post the run summary, if this installation asked for one
     *
     * Nothing here can fail the run. The backups have already happened by this point,
     * and a webhook that would not answer does not change whether they worked - but it
     * is worth a line in the log, because a summary nobody receives is indistinguishable
     * from a run that never started.
     *
     * @param RunSummary $summary what the run did
     * @return void
     */
    protected function notify(RunSummary $summary) : void
    {
        $notifier = app(SlackSummary::class);

        if (!$notifier->shouldSend($summary))
        {
            return;
        }

        try
        {
            $notifier->send($summary);

            $this->log('info', "Sent the run summary to Slack", "Sent the run summary");
        }
        catch (\Throwable $e)
        {
            $this->log(
                'warning',
                "Could not send the run summary - {$e->getMessage()}",
                "Could not send the run summary",
                ['error' => $e->getMessage()]
            );
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

        $summary = app(RunSummary::class);

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

                $summary->stageSkipped($stage);

                continue;
            }

            $this->section($stage);

            $summary->stageRan($stage);

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

                $summary->stageFailed($stage);
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
