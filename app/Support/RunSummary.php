<?php

namespace App\Support;

/**
 * What a backup run actually did, collected while it does it
 *
 * A log answers "what happened, in order", read after the fact and filtered by level.
 * It cannot answer "did last night's backup work", because nothing in a stream of
 * records stands for the run as a whole - there is no line that says twelve sites and
 * three gigabytes and no failures, and there is nowhere for one to go.
 *
 * This is that line. It is a container singleton for the same reason BackupLock is: the
 * stages the cron command runs are separate command objects, and the run is the thing
 * being summarised, not any one of them.
 */
class RunSummary
{
    /**
     * @var float|null when the run started, or null if nothing has started one
     */
    protected $startedAt = null;

    /**
     * @var bool whether this run was told to change nothing
     */
    protected $dryRun = false;

    /**
     * @var array<string, string> stage name => ran|skipped|failed
     */
    protected $stages = [];

    /**
     * @var array<int, array{site: string, stage: string, file: string, bytes: int}>
     */
    protected $backups = [];

    /**
     * @var array<int, array{site: string, stage: string, message: string}>
     */
    protected $failures = [];

    /**
     * @var string|null why the run never began
     */
    protected $blocked = null;

    /**
     * Begin a run. Only the command that owns the whole run calls this.
     */
    public function start(bool $dryRun = false) : void
    {
        $this->startedAt = microtime(true);
        $this->dryRun = $dryRun;
    }

    /**
     * A run that could not begin at all - the lock was held, or could not be taken.
     *
     * Worth reporting on its own, because the failure it describes is a backup that did
     * not happen and left nothing in the log to say so beyond one line.
     */
    public function block(string $reason) : void
    {
        $this->blocked = $reason;
    }

    public function stageRan(string $stage) : void
    {
        $this->stages[$stage] = 'ran';
    }

    public function stageSkipped(string $stage) : void
    {
        $this->stages[$stage] = 'skipped';
    }

    public function stageFailed(string $stage) : void
    {
        $this->stages[$stage] = 'failed';
    }

    public function recordBackup(string $site, string $stage, string $file, int $bytes) : void
    {
        $this->backups[] = compact('site', 'stage', 'file', 'bytes');
    }

    public function recordFailure(string $site, string $stage, string $message) : void
    {
        $this->failures[] = compact('site', 'stage', 'message');
    }

    public function isDryRun() : bool
    {
        return $this->dryRun;
    }

    public function blockedBy() : ?string
    {
        return $this->blocked;
    }

    public function failed() : bool
    {
        return $this->blocked !== null || $this->failures !== [] || in_array('failed', $this->stages, true);
    }

    /**
     * @return array<int, array{site: string, stage: string, message: string}>
     */
    public function failures() : array
    {
        return $this->failures;
    }

    /**
     * @return int backup files written
     */
    public function backupCount() : int
    {
        return count($this->backups);
    }

    /**
     * @return int sites that produced at least one backup
     */
    public function siteCount() : int
    {
        return count(array_unique(array_column($this->backups, 'site')));
    }

    public function bytes() : int
    {
        return (int) array_sum(array_column($this->backups, 'bytes'));
    }

    /**
     * @return array<int, string> stages that ran, in the order they ran
     */
    public function stagesRun() : array
    {
        return array_keys(array_filter($this->stages, fn ($state) => $state !== 'skipped'));
    }

    /**
     * @return array<int, string> stages that were asked to be left out
     */
    public function stagesSkipped() : array
    {
        return array_keys(array_filter($this->stages, fn ($state) => $state === 'skipped'));
    }

    /**
     * @return array<int, string> stages that reported a failure
     */
    public function stagesFailed() : array
    {
        return array_keys(array_filter($this->stages, fn ($state) => $state === 'failed'));
    }

    /**
     * @return float seconds the run took, zero if it never started
     */
    public function seconds() : float
    {
        return $this->startedAt === null ? 0.0 : microtime(true) - $this->startedAt;
    }
}
