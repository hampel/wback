<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Process::fake();
});

it('dumps the database through gzip into the backup disk', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    $destination = backupPath('example.com/database/example.20260813.sql.gz');

    Process::assertRan(fn ($process) => $process->command ===
        "/usr/bin/mysqldump --opt --default-character-set=utf8mb4 --hex-blob example | /bin/gzip -c -f > {$destination}");
});

it('defaults the database name to the site short name', function () {
    useSites(<<<'TOML'
        [zabbix]
        domain = 'zabbix.example.com'
        TOML);

    $this->artisan('database', ['site' => 'zabbix'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, '--hex-blob zabbix |'));
});

it('uses an explicit database name over the site short name', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        database = 'example_prod'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, '--hex-blob example_prod |'));
});

it('skips sites that have the database explicitly disabled', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        database = ''
        TOML);

    $this->artisan('database', ['site' => 'example'])
        ->expectsOutputToContain('No database source specified for example')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('uses the per site charset over the default', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        charset = 'latin1'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, '--default-character-set=latin1'));
});

it('omits the charset when it is configured empty', function () {
    config()->set('backup.mysql.default_charset', '');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => ! str_contains($process->command, '--default-character-set'));
});

it('omits the hex blob option when it is disabled', function () {
    config()->set('backup.mysql.hexblob', false);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => ! str_contains($process->command, '--hex-blob'));
});

it('passes a remote hostname to mysqldump', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        hostname = 'db.internal'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, ' -hdb.internal '));
});

it('increments the filename when a backup already exists for today', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', 'existing');

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_ends_with($process->command, 'example.20260813-2.sql.gz'));
});

it('creates the destination directories', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/database'))->toBeTrue();
});

it('runs nothing on a dry run', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example', '--dry-run' => true])
        ->expectsOutputToContain('Dry run only - no action will be taken')
        ->assertSuccessful();

    Process::assertNothingRan();
});
