<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Process::fake();

    useSource('example.com', ['data/documents/report.pdf']);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        sync = ['data/documents']
        TOML);
});

it('passes when everything checks out', function () {
    $this->artisan('app:validate')
        ->expectsOutputToContain('Everything checks out')
        ->assertSuccessful();
});

it('reports a binary that will not run', function () {
    Process::fake(fn ($process) => str_contains($process->command, 'mysqldump --version')
        ? Process::result(errorOutput: 'sh: 1: /usr/bin/mysqldump: not found', exitCode: 127)
        : Process::result());

    $this->artisan('app:validate')
        ->expectsOutputToContain('/usr/bin/mysqldump: not found')
        ->assertFailed();
});

it('checks the rest of the binaries after one cannot be started at all', function () {
    // a working directory the invoking user cannot traverse fails every spawn, not
    // just the first - this happened on ap1 running from /root, and the run ended at
    // mysqldump with the paths, sites, remotes and logging never looked at
    Process::fake(function ($process) {
        if (str_contains($process->command, 'mysqldump --version')) {
            throw new \RuntimeException(
                'The command "/usr/bin/mysqldump --version" failed.'
                . "\n\nWorking directory: /root"
                . "\n\nError: proc_open(): posix_spawn() failed: Permission denied"
            );
        }

        return Process::result();
    });

    $this->artisan('app:validate')
        // the cause and the cwd, not the "command failed" line the label already says
        ->expectsOutputToContain('posix_spawn() failed: Permission denied (working directory: /root)')
        // the binaries after it, and the sections after those, are still reported
        ->expectsOutputToContain('gzip')
        ->expectsOutputToContain('rclone')
        ->expectsOutputToContain('sites file')
        ->assertFailed();
});

it('reports a database it cannot dump', function () {
    Process::fake(fn ($process) => str_contains($process->command, '--no-data')
        ? Process::result(errorOutput: 'mysqldump: Got error: 1049: Unknown database', exitCode: 2)
        : Process::result());

    $this->artisan('app:validate')
        ->expectsOutputToContain('Unknown database')
        ->assertFailed();
});

it('checks the database without moving any data', function () {
    $this->artisan('app:validate')->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, '--no-data --skip-lock-tables')
        && str_contains($process->command, "'example'"));
});

it('reports the error rather than a warning that came before it', function () {
    // mysqldump leads with a note about ssl verification before saying it could not
    // connect at all, and the second line is the one worth printing
    Process::fake(fn ($process) => str_contains($process->command, '--no-data')
        ? Process::result(errorOutput: "WARNING: option --ssl-verify-server-cert is disabled\n"
            . "mysqldump: Got error: 1698: \"Access denied for user\" when trying to connect", exitCode: 2)
        : Process::result());

    $this->artisan('app:validate')
        ->expectsOutputToContain('Access denied for user')
        ->assertFailed();
});

it('checks a remote database on the port the site sets', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        hostname = 'db.internal'
        port = 3307
        TOML);

    $this->artisan('app:validate')->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, "--no-data --skip-lock-tables -h'db.internal' -P'3307'"));
});

it('reports a missing sites file', function () {
    config()->set('backup.sites_path', '/does/not/exist/wback.toml');

    $this->artisan('app:validate')
        ->expectsOutputToContain('not found at /does/not/exist/wback.toml')
        ->assertFailed();
});

it('reports a site with no domain', function () {
    useSites(<<<'TOML'
        [broken]
        database = 'broken'
        TOML);

    $this->artisan('app:validate')
        ->expectsOutputToContain('no domain specified')
        ->assertFailed();
});

it('reports a file source that is not there', function () {
    useSites(<<<'TOML'
        [missing]
        domain = 'missing.example.com'
        database = ''
        TOML);

    $this->artisan('app:validate')
        ->expectsOutputToContain('source not found')
        ->assertFailed();
});

it('warns rather than fails when a remote path is not there yet', function () {
    Process::fake(fn ($process) => str_contains($process->command, 'lsd')
        ? Process::result(errorOutput: 'directory not found', exitCode: 3)
        : Process::result());

    $this->artisan('app:validate')
        ->expectsOutputToContain('does not exist yet')
        ->expectsOutputToContain('Validated, with warnings')
        ->assertSuccessful();
});

it('fails when a remote cannot be reached at all', function () {
    Process::fake(fn ($process) => str_contains($process->command, 'lsd')
        ? Process::result(errorOutput: 'didn\'t find section in config file', exitCode: 1)
        : Process::result());

    $this->artisan('app:validate')
        ->expectsOutputToContain('find section in config file')
        ->assertFailed();
});

it('warns when logging is going nowhere', function () {
    config()->set(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['null']]);

    $this->artisan('app:validate')
        ->expectsOutputToContain('the null channel discards everything')
        ->assertSuccessful();
});

it('takes and releases the lock', function () {
    $this->artisan('app:validate')->assertSuccessful();

    // released, so a backup can run straight afterwards
    $this->artisan('database', ['site' => 'example'])->assertSuccessful();
});

it('reports the lock being held rather than waiting for it', function () {
    $path = Storage::disk('backup')->path('.wback.lock');

    $lock = fopen($path, 'c');
    flock($lock, LOCK_EX | LOCK_NB);
    fwrite($lock, 'pid 1234, cron, started 2026-08-13 03:00:00');
    fflush($lock);

    $this->artisan('app:validate')
        ->expectsOutputToContain('held by another run [pid 1234, cron, started 2026-08-13 03:00:00]')
        ->assertSuccessful();

    fclose($lock);
});

it('skips the summary check when there is nowhere to send one', function () {
    $this->artisan('app:validate')
        ->expectsOutputToContain('BACKUP_SUMMARY_SLACK_WEBHOOK')
        ->assertSuccessful();
});

it('posts a test message, because a dead webhook says nothing at this end', function () {
    config()->set('backup.summary.slack_webhook', 'https://hooks.slack.com/services/T000/B000/xxx');

    fakeSlack();

    $this->artisan('app:validate')
        ->expectsOutputToContain('test message delivered')
        ->assertSuccessful();

    expect(slackPayload()['text'])->toContain('Test message from app:validate');
});

it('fails validation when Slack will not take the test message', function () {
    config()->set('backup.summary.slack_webhook', 'https://hooks.slack.com/services/T000/B000/xxx');

    fakeSlack(404, 'no_service');

    $this->artisan('app:validate')
        ->expectsOutputToContain('Slack refused the message')
        ->assertFailed();
});
