<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Process::fake();
});

it('copies the whole backup tree for the site to the cloud remote', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', 'dump');

    $this->artisan('cloud', ['site' => 'example'])->assertSuccessful();

    $source = backupPath('example.com');

    Process::assertRan(fn ($process) => $process->command ===
        "/usr/bin/rclone --progress copy {$source} cloud:backups/example.com");
});

it('trims a trailing slash from the configured remote', function () {
    config()->set('backup.rclone.cloud_remote', 'cloud:backups/');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', 'dump');

    $this->artisan('cloud', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_ends_with($process->command, ' cloud:backups/example.com'));
});

it('fails when no cloud remote is configured', function () {
    config()->set('backup.rclone.cloud_remote', null);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('cloud', ['site' => 'example'])
        ->expectsOutputToContain('rclone remote cloud destination not specified in config')
        ->assertFailed();

    Process::assertNothingRan();
});

it('warns and does nothing when the site has no local backups', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('cloud', ['site' => 'example'])
        ->expectsOutputToContain('does not exist for example')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('hands the dry run over to rclone rather than skipping the command', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', 'dump');

    $this->artisan('cloud', ['site' => 'example', '--dry-run' => true])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, '/usr/bin/rclone --dry-run --progress copy'));
});
