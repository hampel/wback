<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * One lock covers every backup command, so the stages cannot run over the top of each
 * other - "database" and "files" stand in for all of them here.
 */

beforeEach(function () {
    Process::fake();

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);
});

/**
 * Hold the lock, as another backup run would.
 *
 * flock is held per open file, not per process, so a handle opened here conflicts with
 * the command's just as another process would.
 */
function holdTheLock(): mixed
{
    $path = Storage::disk('backup')->path('.wback.lock');

    $lock = fopen($path, 'c');
    flock($lock, LOCK_EX | LOCK_NB);
    fwrite($lock, 'pid 1234, files, started 2026-08-13 04:00:00');
    fflush($lock);

    return $lock;
}

it('takes the lock while it runs', function () {
    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->get('.wback.lock'))
        ->toContain('pid ')
        ->toContain('database');
});

it('releases the lock when it finishes', function () {
    $this->artisan('database', ['site' => 'example'])->assertSuccessful();
    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    // the second run got as far as dumping, so the first had let go of the lock
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'mysqldump'), 2);
});

it('skips the run when another backup still holds the lock', function () {
    $lock = holdTheLock();

    $this->artisan('database', ['site' => 'example'])
        ->expectsOutputToContain('Another backup is still running [pid 1234, files, started 2026-08-13 04:00:00]')
        ->assertFailed();

    Process::assertNothingRan();

    fclose($lock);
});

it('holds off every backup command, not just the one that is running', function () {
    $lock = holdTheLock();

    $this->artisan('cloud', ['site' => 'example'])->assertFailed();
    $this->artisan('clean', ['site' => 'example'])->assertFailed();
    $this->artisan('sync', ['site' => 'example'])->assertFailed();

    Process::assertNothingRan();

    fclose($lock);
});

it('leaves the lock alone on a dry run', function () {
    $this->artisan('database', ['site' => 'example', '--dry-run' => true])->assertSuccessful();

    expect(Storage::disk('backup')->exists('.wback.lock'))->toBeFalse();
});

it('runs a dry run even while another backup holds the lock', function () {
    $lock = holdTheLock();

    $this->artisan('database', ['site' => 'example', '--dry-run' => true])->assertSuccessful();

    fclose($lock);
});

it('uses a configured lock file over the backup destination', function () {
    $path = Storage::disk('files')->path('elsewhere.lock');

    config()->set('backup.lock_file', $path);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    expect(file_exists($path))->toBeTrue()
        ->and(Storage::disk('backup')->exists('.wback.lock'))->toBeFalse();
});
