<?php

use Illuminate\Support\Facades\Process;

/*
 * Site selection, and the handling of the sites inventory, live in BaseCommand
 * and are shared by every backup command - "database" stands in for all of them
 * here.
 */

beforeEach(function () {
    Process::fake();
});

it('fails and shows usage when given neither a site nor --all', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database')
        ->expectsOutputToContain('No site provided')
        ->expectsOutputToContain('Usage:')
        ->expectsOutputToContain('Configured sites:')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails when the requested site is not configured', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'missing'])
        ->expectsOutputToContain('Could not find definition for site: missing')
        ->assertFailed();

    Process::assertNothingRan();
});

it('processes every configured site with --all', function () {
    useSites(<<<'TOML'
        [first]
        domain = 'first.example.com'

        [second]
        domain = 'second.example.com'
        TOML);

    $this->artisan('database', ['--all' => true])->assertSuccessful();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'mysqldump'), 2);
});

it('lets --all override a site given as well', function () {
    useSites(<<<'TOML'
        [first]
        domain = 'first.example.com'

        [second]
        domain = 'second.example.com'
        TOML);

    $this->artisan('database', ['site' => 'first', '--all' => true])
        ->expectsOutputToContain('ignoring site argument [first]')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'mysqldump'), 2);
});

it('fails when a site has no domain', function () {
    useSites(<<<'TOML'
        [example]
        database = 'example'
        TOML);

    $this->artisan('database', ['site' => 'example'])
        ->expectsOutputToContain('No domain specified for example')
        ->assertFailed();
});

it('keeps going after a broken site, and still fails the run', function () {
    useSites(<<<'TOML'
        [broken]
        database = 'broken'

        [healthy]
        domain = 'healthy.example.com'
        TOML);

    $this->artisan('database', ['--all' => true])
        ->expectsOutputToContain('No domain specified for broken')
        ->assertFailed();

    Process::assertRan(fn ($process) => str_contains(shellCommand($process->command), "--hex-blob --single-transaction 'healthy' |"));
});

it('keeps going after a backup command fails, and still fails the run', function () {
    // a closure handler replaces the catch-all fake, which would otherwise
    // match first and hand back a successful result
    Process::fake(fn ($process) => str_contains(shellCommand($process->command), "--hex-blob --single-transaction 'broken'")
        ? Process::result(errorOutput: 'mysqldump: unknown database', exitCode: 1)
        : Process::result());

    useSites(<<<'TOML'
        [broken]
        domain = 'broken.example.com'

        [healthy]
        domain = 'healthy.example.com'
        TOML);

    $this->artisan('database', ['--all' => true])->assertFailed();

    Process::assertRan(fn ($process) => str_contains(shellCommand($process->command), "--hex-blob --single-transaction 'healthy' |"));
});

it('fails when the sites file does not exist', function () {
    config()->set('backup.sites_path', '/does/not/exist/wback.toml');

    $this->artisan('database', ['site' => 'example'])
        ->expectsOutputToContain('No sites found at: /does/not/exist/wback.toml')
        ->assertFailed();
});

it('does nothing, successfully, when the inventory lists no sites', function () {
    // a host whose sites are all redirects or placeholders renders an empty file, which
    // is a state the fleet deliberately has. Failing it made every stage of every
    // nightly run fail on such a host, with nothing wrong
    useSites('');

    $this->artisan('database', ['--all' => true])
        ->expectsOutputToContain('No sites configured at:')
        ->assertSuccessful();
});

it('still fails when the inventory is not there at all', function () {
    // the setting pointing somewhere wrong, or a deploy that has not run - which is a
    // fault, and the case the empty file must not be confused with
    config()->set('backup.sites_path', '/does/not/exist/wback.toml');

    $this->artisan('database', ['--all' => true])
        ->expectsOutputToContain('No sites found at: /does/not/exist/wback.toml')
        ->assertFailed();
});

it('fails when the sites file cannot be parsed', function () {
    useSites("[example\ndomain = ");

    $this->artisan('database', ['site' => 'example'])->assertFailed();

    Process::assertNothingRan();
});
