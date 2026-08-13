<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Process::fake();
});

it('zips the source directory into the backup disk', function () {
    $source = useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example'])->assertSuccessful();

    $destination = backupPath('example.com/files/example.20260813.zip');

    Process::assertRan(fn ($process) => $process->command ===
        "/usr/bin/zip -9 --recurse-paths --symlinks '{$destination}' ."
        && $process->path === $source);
});

it('defaults the source to the domain on the files disk', function () {
    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => $process->path === Storage::disk('files')->path('example.com'));
});

it('uses an explicit source path over the files disk', function () {
    $source = useSource('somewhere-else');

    useSites(<<<TOML
        [example]
        domain = 'example.com'
        files = '{$source}'
        TOML);

    $this->artisan('files', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => $process->path === $source);
});

it('skips sites that have files explicitly disabled', function () {
    useSites(<<<'TOML'
        [zabbix]
        domain = 'zabbix.example.com'
        files = ''
        TOML);

    $this->artisan('files', ['site' => 'zabbix'])
        ->expectsOutputToContain('No files source specified for zabbix')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('fails when the source directory does not exist', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example'])
        ->expectsOutputToContain('not found for example')
        ->assertFailed();

    Process::assertNothingRan();
});

it('escapes wildcards in exclude patterns', function () {
    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        exclude = ['data/tmp/*']
        TOML);

    $this->artisan('files', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_ends_with($process->command, " --exclude 'data/tmp/*'"));
});

it('passes every exclude pattern in a single option', function () {
    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        exclude = ['data/tmp/*', 'internal_data/cache/*']
        TOML);

    $this->artisan('files', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_ends_with(
        $process->command,
        " --exclude 'data/tmp/*' 'internal_data/cache/*'"
    ));
});

it('removes the partial archive when zip fails', function () {
    Process::fake(function () {
        Storage::disk('backup')->put('example.com/files/example.20260813.zip', 'partial archive');

        return Process::result(errorOutput: 'zip I/O error: No space left on device', exitCode: 14);
    });

    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example'])
        ->expectsOutputToContain('Removing incomplete backup file')
        ->assertFailed();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260813.zip'))->toBeFalse();
});

it('creates the destination directories', function () {
    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files'))->toBeTrue();
});

it('runs nothing on a dry run', function () {
    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example', '--dry-run' => true])
        ->doesntExpectOutputToContain('Path does not exist when changing permissions')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('creates nothing on a dry run', function () {
    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example', '--dry-run' => true])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com'))->toBeFalse();
});
