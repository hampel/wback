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

it('says which site and which stage failed', function () {
    Process::fake(fn () => Process::result(errorOutput: 'mysqldump: Got error: 1049', exitCode: 2));

    Log::spy();

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertFailed();

    // the exception says which command failed, and nothing about whose backup it was
    Log::shouldHaveReceived('log')
        ->withArgs(fn ($level, $message, $context = []) => $level === 'error'
            && str_contains($message, 'mysqldump: Got error: 1049')
            && $context['site'] === 'example'
            && $context['domain'] === 'example.com'
            && $context['stage'] === 'database');
});
