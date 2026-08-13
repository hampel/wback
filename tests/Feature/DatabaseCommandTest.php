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

    Process::assertRan(fn ($process) => shellCommand($process->command) ===
        "/usr/bin/mysqldump --opt --default-character-set='utf8mb4' --hex-blob --single-transaction 'example'"
        . " | /bin/gzip -c -f > '{$destination}'");
});

it('defaults the database name to the site short name', function () {
    useSites(<<<'TOML'
        [zabbix]
        domain = 'zabbix.example.com'
        TOML);

    $this->artisan('database', ['site' => 'zabbix'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(shellCommand($process->command), " 'zabbix' |"));
});

it('uses an explicit database name over the site short name', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        database = 'example_prod'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(shellCommand($process->command), " 'example_prod' |"));
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

    Process::assertRan(fn ($process) => str_contains(shellCommand($process->command), "--default-character-set='latin1'"));
});

it('omits the charset when it is configured empty', function () {
    config()->set('backup.mysql.default_charset', '');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => ! str_contains(shellCommand($process->command), '--default-character-set'));
});

it('omits the hex blob option when it is disabled', function () {
    config()->set('backup.mysql.hexblob', false);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => ! str_contains(shellCommand($process->command), '--hex-blob'));
});

it('passes a remote hostname to mysqldump', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        hostname = 'db.internal'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(shellCommand($process->command), "-h'db.internal' "));
});

it('dumps from a snapshot rather than locking the tables', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(shellCommand($process->command), ' --single-transaction'));
});

it('locks tables when snapshots are turned off', function () {
    config()->set('backup.mysql.single_transaction', false);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => ! str_contains(shellCommand($process->command), '--single-transaction'));
});

it('lets a site opt out of the snapshot', function () {
    useSites(<<<'TOML'
        [legacy]
        domain = 'legacy.example.com'
        single_transaction = false
        TOML);

    $this->artisan('database', ['site' => 'legacy'])->assertSuccessful();

    Process::assertRan(fn ($process) => ! str_contains(shellCommand($process->command), '--single-transaction'));
});

it('lets a site opt in when snapshots are off by default', function () {
    config()->set('backup.mysql.single_transaction', false);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        single_transaction = true
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(shellCommand($process->command), ' --single-transaction'));
});

it('appends the configured extra options', function () {
    config()->set('backup.mysql.options', '--no-tablespaces --set-gtid-purged=OFF');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(
        shellCommand($process->command),
        "--no-tablespaces --set-gtid-purged=OFF 'example' |"
    ));
});

it('lets a site replace the extra options', function () {
    config()->set('backup.mysql.options', '--no-tablespaces');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        options = '--column-statistics=0'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(function ($process) {
        $command = shellCommand($process->command);

        return str_contains($command, '--column-statistics=0') && ! str_contains($command, '--no-tablespaces');
    });
});

it('runs the dump through a shell that reports pipeline failures', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_starts_with($process->command, "/bin/bash -o pipefail -c '"));
});

it('runs the dump from wherever it was invoked', function () {
    // the dump works entirely in absolute paths, and forcing a working directory
    // fails outright when that directory does not exist
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => $process->path === null);
});

it('runs the dump unwrapped when no shell is configured', function () {
    config()->set('backup.shell', '');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_starts_with($process->command, '/usr/bin/mysqldump '));
});

it('quotes the database name, leaving shell metacharacters inert', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        database = 'example; rm -rf /srv'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(shellCommand($process->command), "'example; rm -rf /srv'"));
});

it('increments the filename when a backup already exists for today', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', 'existing');

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_ends_with(shellCommand($process->command), "example.20260813-2.sql.gz'"));
});

it('removes the partial dump when mysqldump fails', function () {
    // the shell creates the destination as soon as it opens the redirect, so a failed
    // dump leaves a valid gzip file holding part of a database behind
    Process::fake(function () {
        Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', 'partial dump');

        return Process::result(errorOutput: 'mysqldump: Got error: 1049', exitCode: 1);
    });

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])
        ->expectsOutputToContain('Removing incomplete backup file')
        ->assertFailed();

    expect(Storage::disk('backup')->exists('example.com/database/example.20260813.sql.gz'))->toBeFalse();
});

it('reports how big the dump turned out', function () {
    config()->set('backup.mysql.verify', false);   // this one is about the size

    Process::fake(function () {
        Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', str_repeat('x', 2048));

        return Process::result();
    });

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])
        ->expectsOutputToContain('Backed up example.20260813.sql.gz - 2.00 kB')
        ->assertSuccessful();
});

it('reports no size for a dry run', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example', '--dry-run' => true])
        ->doesntExpectOutputToContain('Backed up')
        ->assertSuccessful();
});

it('keeps the dump when it succeeds', function () {
    Process::fake(function () {
        Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', dumpArchive());

        return Process::result();
    });

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/database/example.20260813.sql.gz'))->toBeTrue();
});

it('fails the site when the dump stopped partway', function () {
    // a mysqldump killed partway leaves a valid gzip file holding half a database, and
    // exits 0 if the gzip it was piped into was happy
    Process::fake(function () {
        Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', dumpArchive(complete: false));

        return Process::result();
    });

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])
        ->expectsOutputToContain('is incomplete - mysqldump did not finish writing it')
        ->assertFailed();

    // kept, because a dump that fails this check is worth looking at
    expect(Storage::disk('backup')->exists('example.com/database/example.20260813.sql.gz'))->toBeTrue();
});

it('passes a dump that ran to the end', function () {
    Process::fake(function () {
        Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', dumpArchive());

        return Process::result();
    });

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();
});

it('can have verification turned off', function () {
    config()->set('backup.mysql.verify', false);

    Process::fake(function () {
        Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', dumpArchive(complete: false));

        return Process::result();
    });

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();
});

it('can have verification turned off for one site', function () {
    Process::fake(function () {
        Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', dumpArchive(complete: false));

        return Process::result();
    });

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        verify = false
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();
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
        ->doesntExpectOutputToContain('Path does not exist when changing permissions')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('creates nothing on a dry run', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example', '--dry-run' => true])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com'))->toBeFalse();
});
