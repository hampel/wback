<?php

use App\Logging\HostnameProcessor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * Every record carries the host it came from, so one Slack webhook can serve a whole
 * fleet rather than one webhook per machine to tell the alerts apart.
 */

/**
 * Log to a file channel and give back what was written.
 */
function loggedTo(callable $write): string
{
    $path = Storage::disk('backup')->path('test.log');

    config()->set([
        'logging.default' => 'single',
        'logging.channels.single.path' => $path,
    ]);

    $write();

    return file_get_contents($path);
}

it('stamps the log with the host the backup ran on', function () {
    config()->set('logging.hostname', 'web01');

    $log = loggedTo(fn () => Log::error('Backup failed'));

    expect($log)->toContain('Backup failed')
        ->and($log)->toContain('{"hostname":"web01"}');
});

it('leaves the log unstamped when there is no hostname to stamp it with', function () {
    config()->set('logging.hostname', '');

    $log = loggedTo(fn () => Log::error('Backup failed'));

    expect($log)->toContain('Backup failed')
        ->and($log)->not->toContain('hostname');
});

it('stamps the slack channel too, which is the point of the exercise', function () {
    config()->set([
        'logging.hostname' => 'web01',
        'logging.channels.slack.url' => 'https://hooks.slack.test/nothing-is-sent',
    ]);

    $processors = Log::channel('slack')->getLogger()->getProcessors();

    $stamp = collect($processors)->first(fn ($processor) => $processor instanceof HostnameProcessor);

    // slack renders extra as fields on the attachment, so this is the "Hostname" field
    $record = new LogRecord(new DateTimeImmutable, 'production', Level::Error, 'Backup failed');

    expect($stamp)->not->toBeNull()
        ->and($stamp($record)->extra)->toBe(['hostname' => 'web01']);
});

it('names the machine itself unless told otherwise', function () {
    $this->refreshApplication();

    expect(config('logging.hostname'))->toBe(gethostname());

    putenv('LOG_HOSTNAME=backups.example.com');
    $this->refreshApplication();

    expect(config('logging.hostname'))->toBe('backups.example.com')
        ->and(config('logging.channels.slack.username'))->toBe('backups.example.com');
})->after(fn () => putenv('LOG_HOSTNAME'));

it('posts under the application name when there is no hostname to post under', function () {
    putenv('LOG_HOSTNAME=');
    $this->refreshApplication();

    // not a second literal 'wback' to keep in step: app.name is where the name is set
    expect(config('logging.hostname'))->toBe('')
        ->and(config('logging.channels.slack.username'))->not->toBeEmpty()
        ->and(config('logging.channels.slack.username'))->toBe(config('app.name'));
})->after(fn () => putenv('LOG_HOSTNAME'));

it('says which site and which stage failed', function () {
    Process::fake(fn () => Process::result(errorOutput: 'mysqldump: Got error: 1049', exitCode: 2));

    Log::spy();

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertFailed();

    // The message is a CONSTANT, so a log store can count these as one thing; what
    // varies goes in context. The exception goes in as an object rather than as text,
    // which is what gives the JSON formatter a class and a trace to record - and the
    // console still shows the operator what actually failed.
    Log::shouldHaveReceived('log')
        ->withArgs(fn ($level, $message, $context = []) => $level === 'error'
            && $message === 'Site backup failed'
            && $context['site'] === 'example'
            && $context['domain'] === 'example.com'
            && $context['stage'] === 'database'
            && $context['exception'] instanceof \Throwable
            && str_contains($context['exception']->getMessage(), 'mysqldump: Got error: 1049'));
});

it('masks a credential in the command it logs, as app:config does', function () {
    // BACKUP_MYSQLDUMP_OPTIONS and the rclone option strings are inserted as written, so
    // a command line is the one place in this application where a password could appear.
    // Nothing on the fleet puts one there; a log store keeps what it is given for months.
    config()->set('backup.mysql.options', '--password=hunter2');

    Process::fake();
    Log::spy();

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example', '-vvv' => true])->assertSuccessful();

    Log::shouldHaveReceived('log')
        ->withArgs(function ($level, $message, $context = []) {
            if ($level !== 'debug' || $message !== 'Executing command') {
                return false;
            }

            return str_contains($context['command'], '--password=redacted')
                && ! str_contains($context['command'], 'hunter2');
        });
});

it('masks a credential in a FAILED command, which is the path that nearly got missed', function () {
    // A failing process puts its whole command line into the exception message, and that
    // message reaches the console, the log, and the Slack run summary through
    // failureReason(). Masking the debug line alone left all three leaking.
    config()->set('backup.mysql.options', '--password=hunter2');

    Process::fake(fn () => Process::result(errorOutput: 'mysqldump: got error', exitCode: 2));
    Log::spy();

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    // the console half - Log::spy() cannot see it, since it records the LOG message
    $this->artisan('database', ['site' => 'example'])
        ->doesntExpectOutputToContain('hunter2')
        ->assertFailed();

    Log::shouldHaveReceived('log')
        ->withArgs(fn ($level, $message, $context = []) => $level === 'error'
            && $message === 'Site backup failed'
            && ! str_contains($context['exception']->getMessage(), 'hunter2')
            && str_contains($context['exception']->getMessage(), 'redacted'));
});

it('keeps the real exception when there is no credential to mask', function () {
    // masking costs the class and the trace, so it only happens when it has to
    Process::fake(fn () => Process::result(errorOutput: 'mysqldump: got error', exitCode: 2));
    Log::spy();

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertFailed();

    Log::shouldHaveReceived('log')
        ->withArgs(fn ($level, $message, $context = []) => $message === 'Site backup failed'
            && $context['exception'] instanceof \Illuminate\Process\Exceptions\ProcessFailedException);
});

it('masks a credential in the failure reason the run summary sends to Slack', function () {
    // failureReason() prefers the process's error output, and a tool that echoes its own
    // command line puts the credential there. That text becomes the Slack message, which
    // is the one destination of the three that leaves the machine.
    config()->set('backup.mysql.options', '--password=hunter2');

    Process::fake(fn () => Process::result(
        errorOutput: "mysqldump: command was: mysqldump --password=hunter2 db",
        exitCode: 2
    ));

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertFailed();

    $failures = app(App\Support\RunSummary::class)->failures();

    expect($failures)->not->toBeEmpty();

    foreach ($failures as $failure)
    {
        $text = is_array($failure) ? implode(' ', array_map('strval', $failure)) : (string) $failure;

        expect($text)->not->toContain('hunter2')
            ->and($text)->toContain('redacted');
    }
});

it('masks it in the fallback branch too, where the process failed silently', function () {
    // no output at all, so failureReason() falls through to the exception message - which
    // for a failed process IS the command line. Both branches need the same treatment and
    // only mutation testing showed that the second one was unguarded.
    config()->set('backup.mysql.options', '--password=hunter2');

    Process::fake(fn () => Process::result(output: '', errorOutput: '', exitCode: 2));

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertFailed();

    $text = implode(' ', array_map(
        fn ($f) => is_array($f) ? implode(' ', array_map('strval', $f)) : (string) $f,
        app(App\Support\RunSummary::class)->failures()
    ));

    expect($text)->not->toContain('hunter2')
        ->and($text)->toContain('redacted');
});
