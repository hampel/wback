<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Process::fake();
});

it('syncs a configured path to the sync remote', function () {
    useSource('example.com', ['data/documents/report.pdf']);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        sync = ['data/documents']
        TOML);

    $this->artisan('sync', ['site' => 'example'])->assertSuccessful();

    $source = Storage::disk('files')->path('example.com/data/documents');

    Process::assertRan(fn ($process) => $process->command ===
        "/usr/bin/rclone --progress sync '{$source}' 'sync:live/example.com/sync/data/documents'");
});

it('syncs every configured path', function () {
    useSource('example.com', ['data/documents/report.pdf', 'data/media/logo.png']);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        sync = ['data/documents', 'data/media']
        TOML);

    $this->artisan('sync', ['site' => 'example'])->assertSuccessful();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'rclone'), 2);
});

it('accepts a single sync path given as a string', function () {
    useSource('example.com', ['data/documents/report.pdf']);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        sync = 'data/documents'
        TOML);

    $this->artisan('sync', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_ends_with($process->command, " 'sync:live/example.com/sync/data/documents'"));
});

it('resolves sync paths against an explicit files path', function () {
    $source = useSource('somewhere-else', ['data/documents/report.pdf']);

    useSites(<<<TOML
        [example]
        domain = 'example.com'
        files = '{$source}'
        sync = ['data/documents']
        TOML);

    $this->artisan('sync', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, "sync '{$source}/data/documents' "));
});

it('quotes sync paths containing spaces', function () {
    useSource('example.com', ['data/My Documents/report.pdf']);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        sync = ['data/My Documents']
        TOML);

    $this->artisan('sync', ['site' => 'example'])->assertSuccessful();

    $source = Storage::disk('files')->path('example.com/data/My Documents');

    Process::assertRan(fn ($process) => $process->command ===
        "/usr/bin/rclone --progress sync '{$source}' 'sync:live/example.com/sync/data/My Documents'");
});

it('skips sites with no sync configuration', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('sync', ['site' => 'example'])
        ->expectsOutputToContain('No sync config specified for example')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('warns when a sync path does not exist', function () {
    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        sync = ['data/missing']
        TOML);

    $this->artisan('sync', ['site' => 'example'])
        ->expectsOutputToContain('does not exist for example')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('fails when no sync remote is configured', function () {
    config()->set('backup.rclone.sync_remote', null);

    useSource('example.com', ['data/documents/report.pdf']);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        sync = ['data/documents']
        TOML);

    $this->artisan('sync', ['site' => 'example'])
        ->expectsOutputToContain('rclone remote sync destination not specified in config')
        ->assertFailed();

    Process::assertNothingRan();
});

it('hands the dry run over to rclone rather than skipping the command', function () {
    useSource('example.com', ['data/documents/report.pdf']);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        sync = ['data/documents']
        TOML);

    $this->artisan('sync', ['site' => 'example', '--dry-run' => true])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, '/usr/bin/rclone --dry-run --progress sync'));
});
