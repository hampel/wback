<?php

namespace App\Support;

/**
 * Taking the backup lock for the length of a command
 *
 * Expects the using command to have a --dry-run option and the LogsToConsole trait.
 *
 * A command called by another that already holds the lock - the stages the cron command
 * runs - leaves it alone, both to take and to release.
 */
trait LocksBackups
{
    /**
     * Whether this command is the one holding the lock
     *
     * @var bool
     */
    protected $holdsLock = false;

    /**
     * @return bool false if another backup run holds the lock
     */
    protected function acquireLock() : bool
    {
        // a dry run changes nothing, so there is nothing to hold anyone off
        if ($this->option('dry-run'))
        {
            return true;
        }

        $lock = app(BackupLock::class);

        if ($lock->isHeld())
        {
            return true;
        }

        try
        {
            if (!$lock->acquire($this->getName()))
            {
                $holder = $lock->holder();

                $this->log(
                    'error',
                    "Another backup is still running [{$holder}] - skipping this run",
                    "Another backup is still running - skipping this run",
                    ['holder' => $holder, 'lock' => $lock->path()]
                );

                return false;
            }
        }
        catch (\RuntimeException $e)
        {
            $this->log('error', $e->getMessage(), $e->getMessage(), ['lock' => $lock->path()]);

            return false;
        }

        $this->holdsLock = true;

        return true;
    }

    protected function releaseLock() : void
    {
        if ($this->holdsLock)
        {
            app(BackupLock::class)->release();

            $this->holdsLock = false;
        }
    }
}
