<?php

use Illuminate\Support\Facades\Log;
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

it('heads each group of checks at the same margin as the check rows', function () {
    // the only assertion in this file on the shape of the report rather than its
    // content - console-report draws the headings, so a package change is what would
    // move them, and nothing else here would notice
    $this->artisan('app:validate')
        ->expectsOutput('  Binaries')
        ->expectsOutput('  Paths')
        ->expectsOutput('  Sites')
        ->expectsOutput('  Remotes')
        ->expectsOutput('  Logging')
        ->expectsOutput('  Summary')
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

it('warns rather than fails when a file source exists but is empty', function () {
    // the nightly refuses an empty source, so validate says so at provisioning time
    // instead of leaving it to 03:17 - but exit-code-neutral, because pyinfra runs
    // this as a deploy gate and reads nothing but the status
    useSource('empty.example.com', []);

    useSites(<<<'TOML'
        [empty]
        domain = 'empty.example.com'
        database = ''
        TOML);

    $this->artisan('app:validate')
        ->expectsOutputToContain('source is empty, so backing it up would fail')
        ->expectsOutputToContain('Validated, with warnings')
        ->assertSuccessful();
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

/*
|--------------------------------------------------------------------------
| The log sweep, and --unattended
|--------------------------------------------------------------------------
|
| The sweep is what proves LOG_SLACK_LEVEL and the webhook - neither can be
| checked from the sending end, and the records arriving check both. So the
| flag suppresses it rather than the code bounding it by channel.
|
*/

/**
 * Point the log stack at a slack channel that will accept everything from $level up.
 *
 * The URL is `.test` on purpose, as LoggingTest's is: Monolog's SlackWebhookHandler
 * runs its own curl rather than the PSR-18 client the container holds, so fakeSlack()
 * is not in its path and cannot stand in for a real webhook here. Every caller also
 * spies the Log facade so the handler is never built - but caller discipline is what
 * fails quietly, and a reserved TLD that cannot resolve is what fails loudly.
 */
function useSlackLog(string $level = 'error'): void
{
    config()->set([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['single', 'slack'],
        'logging.channels.slack.url' => 'https://hooks.slack.test/nothing-is-sent',
        'logging.channels.slack.level' => $level,
    ]);
}

it('says how many records the sweep posted, so the count can be checked against the channel', function () {
    // the facade, so the sweep cannot reach Monolog's own curl - which Http::fake()
    // does not intercept, and which would post to a real webhook
    Log::spy();

    useSlackLog('error');

    $this->artisan('app:validate')
        ->expectsOutputToContain('posted 4 records at error and above: error, critical, alert, emergency')
        ->assertSuccessful();

    Log::shouldHaveReceived('log')->with('emergency', 'Validation test message [emergency]', [])->once();
});

it('counts from whatever the threshold is set to', function () {
    Log::spy();

    useSlackLog('warning');

    $this->artisan('app:validate')
        ->expectsOutputToContain('posted 5 records at warning and above: warning, error, critical, alert, emergency')
        ->assertSuccessful();
});

it('does not post the sweep under --unattended', function () {
    Log::spy();

    useSlackLog('error');

    $this->artisan('app:validate', ['--unattended' => true])
        ->expectsOutputToContain('nothing was posted - --unattended')
        ->assertSuccessful();

    Log::shouldNotHaveReceived('log', ['emergency', 'Validation test message [emergency]', []]);
});

it('still reports the logging configuration under --unattended', function () {
    Log::spy();

    useSlackLog('error');

    // the flag turns off the sends, not the section - a quiet run still has to say
    // where the logs go, or it has stopped validating the thing it was asked about
    $this->artisan('app:validate', ['--unattended' => true])
        ->expectsOutputToContain('single,slack')
        ->assertSuccessful();
});

it('does not send the run summary test message under --unattended', function () {
    config()->set('backup.summary.slack_webhook', 'https://hooks.slack.com/services/T000/B000/xxx');

    fakeSlack();

    $this->artisan('app:validate', ['--unattended' => true])
        ->expectsOutputToContain('configured, but nothing was sent - --unattended')
        ->assertSuccessful();

    expect(slackPayloads())->toBeEmpty();
});

it('warns when the slack channel is in the stack with no webhook to post to', function () {
    Log::spy();

    useSlackLog('error');
    config()->set('logging.channels.slack.url', '');

    $this->artisan('app:validate')
        ->expectsOutputToContain('no webhook, so nothing was posted')
        ->assertSuccessful();
});

it('skips the delivery report when nothing in the stack posts to slack', function () {
    $this->artisan('app:validate')
        ->expectsOutputToContain('nothing in the log stack posts to slack')
        ->assertSuccessful();
});
