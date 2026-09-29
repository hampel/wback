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
        "/usr/bin/zip -9 --quiet --recurse-paths --symlinks '{$destination}' ."
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

it('fails when the source directory is empty, without leaning on zip to say so', function () {
    // zip calls this "Nothing to do!" and exits 12, which is a half-finished migration
    // and a deliberately empty placeholder both described in zip's terms rather than
    // the site's. CronCommandTest covers the part that matters - the blast radius
    useSource('example.com', []);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example'])
        ->expectsOutputToContain('is empty for example')
        ->assertFailed();

    Process::assertNothingRan();
});

it('backs up a site whose only content is a dot file', function () {
    // zip -r . takes dot files, so an empty directory means empty to zip too - the
    // check has to agree with the command it is standing in front of
    useSource('example.com', ['.htaccess']);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example'])->assertSuccessful();
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

it('reports how big the archive turned out', function () {
    Process::fake(function () {
        Storage::disk('backup')->put('example.com/files/example.20260813.zip', str_repeat('x', 1536));

        return Process::result();
    });

    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('files', ['site' => 'example'])
        ->expectsOutputToContain('Backed up example.20260813.zip - 1.50 kB')
        ->assertSuccessful();
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

/*
|--------------------------------------------------------------------------
| How loud zip is allowed to be
|--------------------------------------------------------------------------
|
| zip has no log levels, so without being told otherwise it prints a line per
| file added. Under cron that is tens of thousands of lines nobody reads, and
| enough of them that the MTA refuses to mail the output at all - which costs
| the channel that carries a crash occurring before the log or the summary
| starts.
|
| The suite's output is never decorated, so these exercise the cron shape. The
| interactive branch is the one case a test cannot produce.
|
*/

/** The verbosity flag zip was given for a run of the files stage. */
function zipFlagsFor(array $arguments = []): string
{
    useSource('example.com');
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    test()->artisan('files', ['site' => 'example'] + $arguments)->assertSuccessful();

    $command = '';
    Process::assertRan(function ($process) use (&$command) {
        if (str_contains($process->command, 'zip')) { $command = $process->command; }
        return true;
    });

    preg_match('/-9(\S*|\s--\S+)? --recurse-paths/', $command, $m);

    return trim($m[1] ?? '');
}

it('tells zip to be quiet when nothing is watching, which is what cron is', function () {
    expect(zipFlagsFor())->toBe('--quiet');
});

it('tells zip everything under -v', function () {
    expect(zipFlagsFor(['-v' => true]))->toBe('--verbose');
});

it('keeps zip quiet under --quiet', function () {
    expect(zipFlagsFor(['--quiet' => true]))->toBe('--quiet');
});

it('leaves rclone alone, because its own default log level is already quiet', function () {
    // rclone's stats are INFO records and it defaults to NOTICE, so the --stats flags
    // print nothing unless the run is verbose. --quiet here would suppress nothing and
    // would read as asking for stats and silencing them in one command.
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    // cloud copies the backup tree, so there has to be something in it
    Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', 'dump');

    $this->artisan('cloud', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, 'rclone')
        && str_contains($process->command, '--stats-one-line --stats 1m')
        && ! str_contains($process->command, '--quiet'));
});

