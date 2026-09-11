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

it('sends the sites that worked offsite, even though another site failed', function () {
    // the blast radius question, and the one worth a standing test: a placeholder with
    // an empty docroot must not cost the real site beside it its offsite copy. Reported
    // from production on 2026-08-24 as though it did - it does not, and this says so.
    useSource('example.com', ['index.php']);
    useSource('placeholder.example', []);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'

        [placeholder]
        domain = 'placeholder.example'
        TOML);

    $this->artisan('cron')
        ->expectsOutputToContain('is empty for placeholder')
        ->expectsOutputToContain('Backup stage [files] failed')
        ->assertFailed();

    $ran = collect(ranCommands());

    // the good site was archived, and that archive was sent to cloud storage
    expect($ran->contains(fn ($command) => str_contains($command, 'zip')
        && str_contains($command, 'example.20260813.zip')))->toBeTrue()
        ->and($ran->contains(fn ($command) => str_contains($command, 'copy')
            && str_contains($command, 'example.com')))->toBeTrue();

    // and the run still fails, so cron mails and the summary fires
});

it('runs every later stage after a stage fails', function () {
    recordCommands(fn ($process) => str_contains($process->command, 'zip')
        ? Process::result(errorOutput: 'zip error: Nothing to do!', exitCode: 12)
        : Process::result());

    $this->artisan('cron')
        ->expectsOutputToContain('Backup stage [files] failed')
        ->doesntExpectOutputToContain('Skipping [cloud]')
        ->assertFailed();

    // cloud and sync are what a failed files stage was thought to take down with it
    $ran = collect(ranCommands());

    expect($ran->contains(fn ($command) => str_contains($command, ' copy ')))->toBeTrue()
        ->and($ran->contains(fn ($command) => str_contains($command, ' sync ')))->toBeTrue();
});

it('skips the stages it is told to', function () {
    // a server still being built has no remotes yet, and every site would otherwise
    // fail the cloud stage for want of one
    $this->artisan('cron', ['--no-cloud' => true, '--no-sync' => true])
        ->expectsOutputToContain('Skipping [cloud]')
        ->expectsOutputToContain('Skipping [sync]')
        ->assertSuccessful();

    $ran = ranCommands();

    expect($ran)->toHaveCount(2)
        ->and($ran[0])->toContain('mysqldump')
        ->and($ran[1])->toContain('zip');
});

it('still fails a stage that is meant to run but cannot', function () {
    config()->set('backup.rclone.cloud_remote', null);

    $this->artisan('cron', ['--no-sync' => true])
        ->expectsOutputToContain('rclone remote cloud destination not specified in config')
        ->assertFailed();
});

it('can be told to skip everything', function () {
    $this->artisan('cron', [
        '--no-database' => true,
        '--no-files' => true,
        '--no-cloud' => true,
        '--no-sync' => true,
        '--no-clean' => true,
    ])->assertSuccessful();

    expect(ranCommands())->toBeEmpty();
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
