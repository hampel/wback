<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    recordCommands();

    useSource('example.com', ['data/documents/report.pdf']);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        sync = ['data/documents']
        TOML);
});

/**
 * Record the commands as they run, in order.
 *
 * The process factory keeps its recording to itself, and the order the stages run in is
 * the thing being tested.
 */
function recordCommands(?callable $result = null): void
{
    $GLOBALS['wback_ran'] = [];

    Process::fake(function ($process) use ($result) {
        $GLOBALS['wback_ran'][] = shellCommand($process->command);

        return $result ? $result($process) : Process::result();
    });
}

/**
 * The external commands that ran, in the order they ran.
 */
function ranCommands(): array
{
    return $GLOBALS['wback_ran'] ?? [];
}

it('runs every backup in the order they depend on each other', function () {
    $this->artisan('cron')->assertSuccessful();

    $ran = ranCommands();

    expect($ran)->toHaveCount(4)
        ->and($ran[0])->toContain('mysqldump')
        ->and($ran[1])->toContain('zip')
        ->and($ran[2])->toContain('rclone --stats-one-line --stats 1m copy')
        ->and($ran[3])->toContain('rclone --stats-one-line --stats 1m sync');
});

it('holds the lock itself, so the stages do not fight over it', function () {
    $this->artisan('cron')->assertSuccessful();

    // every stage ran, and the lock records the run rather than the last stage of it
    expect(ranCommands())->toHaveCount(4)
        ->and(Storage::disk('backup')->get('.wback.lock'))->toContain('cron');
});

it('carries on after a stage fails, and fails the run', function () {
    recordCommands(fn ($process) => str_contains($process->command, 'mysqldump')
        ? Process::result(errorOutput: 'mysqldump: Got error: 1049', exitCode: 1)
        : Process::result());

    $this->artisan('cron')
        ->expectsOutputToContain('Backup stage [database] failed')
        ->assertFailed();

    // the file backup still happened, despite the database stage failing before it
    expect(collect(ranCommands())->contains(fn ($command) => str_contains($command, 'zip')))->toBeTrue();
});

it('passes a dry run down to every stage', function () {
    $this->artisan('cron', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run only - no action will be taken')
        ->assertSuccessful();

    // rclone is handed the dry run rather than skipping it, so sync still runs
    expect(collect(ranCommands())->every(fn ($command) => str_contains($command, '--dry-run')))->toBeTrue()
        ->and(collect(ranCommands())->contains(fn ($command) => str_contains($command, 'rclone')))->toBeTrue()
        ->and(Storage::disk('backup')->exists('.wback.lock'))->toBeFalse();
});

it('does not start while another backup run is going', function () {
    $path = Storage::disk('backup')->path('.wback.lock');

    $lock = fopen($path, 'c');
    flock($lock, LOCK_EX | LOCK_NB);
    fwrite($lock, 'pid 1234, cron, started 2026-08-13 03:00:00');
    fflush($lock);

    $this->artisan('cron')
        ->expectsOutputToContain('Another backup is still running [pid 1234, cron, started 2026-08-13 03:00:00]')
        ->assertFailed();

    Process::assertNothingRan();

    fclose($lock);
});
