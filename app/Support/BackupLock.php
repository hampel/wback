<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * The lock that keeps two backup runs off each other
 *
 * Held for as long as the process lives: flock is released by the kernel when the
 * process ends, however it ends, so a run that crashes leaves nothing to clean up. The
 * file it locks is only a place to hang the lock and to record who holds it.
 *
 * Bound as a singleton, because the cron command takes the lock for a whole run and the
 * commands it calls have to see that it is already held - flock conflicts with itself
 * when the same process opens the file twice.
 */
class BackupLock
{
    /**
     * @var resource|null
     */
    protected $handle = null;

    /**
     * Take the lock, recording who has it.
     *
     * @param string $command command taking the lock
     * @return bool false if another run holds it
     * @throws \RuntimeException if the lock file cannot be opened
     */
    public function acquire(string $command) : bool
    {
        $path = $this->path();

        File::ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'c');

        if ($handle === false)
        {
            throw new \RuntimeException("Could not open lock file [{$path}]");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB))
        {
            fclose($handle);

            return false;
        }

        // leave enough behind to say what is holding it, for whoever gets skipped
        ftruncate($handle, 0);
        fwrite($handle, sprintf(
            "pid %d, %s, started %s",
            getmypid(),
            $command,
            Carbon::now(new \DateTimeZone(config('backup.timezone')))->toDateTimeString()
        ));
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    public function release() : void
    {
        if (is_resource($this->handle))
        {
            fclose($this->handle);

            $this->handle = null;
        }
    }

    /**
     * @return bool whether this process already holds the lock
     */
    public function isHeld() : bool
    {
        return is_resource($this->handle);
    }

    /**
     * @return string what the lock file says about whoever holds it
     */
    public function holder() : string
    {
        return trim((string) @file_get_contents($this->path()));
    }

    /**
     * How long the current holder has had the lock, in whole minutes.
     *
     * Taken from the lock file's modification time, not from the timestamp acquire()
     * writes inside it. Both record the same moment, but mtime is an instant while the
     * text is a local-time string carrying no offset - so if the writing run and the
     * reading run disagree about backup.timezone, the text is out by that offset and a
     * lock taken minutes ago reads as hours old. Measured 2026-10-10: a 30-minute-old
     * lock written under Australia/Sydney and read under UTC reported 10 hours 29
     * minutes, which is more than enough to call a healthy run stuck.
     *
     * Only a successful acquire writes to the file, so its mtime is when the lock was
     * last taken rather than when it was last looked at.
     *
     * @return int|null minutes held, or null if the file has no readable mtime
     */
    public function heldFor() : ?int
    {
        $path = $this->path();

        clearstatcache(true, $path);

        $mtime = @filemtime($path);

        if ($mtime === false)
        {
            return null;
        }

        return (int) max(0, intdiv(time() - $mtime, 60));
    }

    public function path() : string
    {
        $path = config('backup.lock_file');

        return empty($path) ? Storage::disk('backup')->path('.wback.lock') : $path;
    }
}
