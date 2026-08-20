<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * The run summary is the one message that says whether last night's backup worked. A log
 * channel cannot say it: it reports records, so it can only ever report trouble, and a
 * run where nothing failed produces the same silence as a cron entry nobody installed.
 *
 * The assertions here are against the payload Slack would receive, because that payload
 * is the product - the same way the rest of the suite asserts on the command strings.
 */

beforeEach(function () {
    produceBackups();

    useSource('example.com');

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    config()->set([
        'backup.summary.slack_webhook' => 'https://hooks.slack.com/services/T000/B000/xxx',
        'backup.summary.notify' => 'always',
        'logging.hostname' => 'web01',
    ]);

    fakeSlack();
});

/**
 * Write the backup files the faked binaries would have written.
 *
 * The summary counts what was produced, and it counts it by looking at the files - so a
 * fake that leaves nothing behind is a run that backed nothing up, which is exactly what
 * it would report.
 */
function produceBackups(): void
{
    Process::fake(function ($process) {
        if (str_contains($process->command, 'mysqldump')) {
            Storage::disk('backup')->put('example.com/database/example.20260813.sql.gz', dumpArchive());
        }

        // the zip binary by its full path: "gzip" contains "zip", and matching loosely
        // writes the file archive during the database stage
        if (str_contains($process->command, '/usr/bin/zip')) {
            Storage::disk('backup')->put('example.com/files/example.20260813.zip', str_repeat('x', 2048));
        }

        return Process::result();
    });
}

/**
 * Fail one stage, by failing the binary it runs.
 */
function failTheBinary(string $binary, string $error): void
{
    Process::fake(fn ($process) => str_contains($process->command, $binary)
        ? Process::result(errorOutput: $error, exitCode: 1)
        : Process::result());
}

it('says the backup completed, and what it produced', function () {
    $this->artisan('cron')->assertSuccessful();

    $payload = slackPayload();
    $fields = slackFields($payload);

    expect($payload['text'])->toBe('Backup completed on web01')
        ->and($payload['attachments'][0]['color'])->toBe('good')
        ->and($fields['Sites'])->toBe('1')
        ->and($fields['Backups'])->toBe('2')
        ->and($fields['Stages'])->toBe('database, files, cloud, sync, clean')
        ->and($fields)->toHaveKey('Written')
        ->and($fields)->toHaveKey('Duration')
        ->and($fields)->not->toHaveKey('Failures');
});

it('names the site and the stage that failed', function () {
    failTheBinary('mysqldump', 'mysqldump: Got error: 1049 Unknown database');

    $this->artisan('cron')->assertFailed();

    $payload = slackPayload();

    expect($payload['text'])->toBe('Backup failed on web01')
        ->and($payload['attachments'][0]['color'])->toBe('danger')
        ->and(slackFields($payload)['Failures'])->toBe('1')
        ->and($payload['attachments'][0]['text'])
            ->toContain('example (database)')
            ->toContain('1049 Unknown database');
});

it('reports the stages that were left out', function () {
    $this->artisan('cron', ['--no-cloud' => true, '--no-sync' => true])->assertSuccessful();

    $fields = slackFields(slackPayload());

    expect($fields['Stages'])->toBe('database, files, clean')
        ->and($fields['Skipped'])->toBe('cloud, sync');
});

it('says a run that never started did not run, and why', function () {
    $path = Storage::disk('backup')->path('.wback.lock');

    $lock = fopen($path, 'c');
    flock($lock, LOCK_EX | LOCK_NB);
    fwrite($lock, 'pid 1234, cron, started 2026-08-13 04:00:00');
    fflush($lock);

    $this->artisan('cron')->assertFailed();

    $payload = slackPayload();

    expect($payload['text'])->toBe('Backup did not run on web01')
        ->and($payload['attachments'][0]['color'])->toBe('danger')
        ->and($payload['attachments'][0]['text'])->toContain('pid 1234')
        // nothing happened, and a row of zeroes would read as a run that found nothing
        ->and($payload['attachments'][0])->not->toHaveKey('fields');

    flock($lock, LOCK_UN);
    fclose($lock);
});

it('marks a dry run as one, so the channel is safe to test against', function () {
    $this->artisan('cron', ['--dry-run' => true])->assertSuccessful();

    expect(slackPayload()['text'])->toBe('[Dry run] Backup completed on web01');
});

it('sends nothing when no webhook is configured', function () {
    config()->set('backup.summary.slack_webhook', '');

    $this->artisan('cron')->assertSuccessful();

    expect(slackPayloads())->toBeEmpty();
});

it('holds its peace on a good run when only failures were asked for', function () {
    config()->set('backup.summary.notify', 'failure');

    $this->artisan('cron')->assertSuccessful();

    expect(slackPayloads())->toBeEmpty();
});

it('still speaks up on a failure when only failures were asked for', function () {
    config()->set('backup.summary.notify', 'failure');

    failTheBinary('mysqldump', 'mysqldump: Got error: 1049');

    $this->artisan('cron')->assertFailed();

    expect(slackPayload()['text'])->toBe('Backup failed on web01');
});

it('does not fail the run when Slack will not take the message', function () {
    fakeSlack(404, 'no_service');

    // the backups worked; whether Slack heard about them does not change that
    $this->artisan('cron')
        ->expectsOutputToContain('Could not send the run summary')
        ->assertSuccessful();
});

it('stamps the machine and the version on the message', function () {
    $this->artisan('cron')->assertSuccessful();

    expect(slackPayload()['attachments'][0]['footer'])->toContain('on web01');
});

it('says which stage failed when no one site is to blame', function () {
    // an inventory that will not parse fails the stage without failing a site, and a red
    // message with nothing in it saying why is worse than no message
    useSites('this is not toml [[[');

    $this->artisan('cron')->assertFailed();

    $payload = slackPayload();

    expect($payload['text'])->toBe('Backup failed on web01')
        ->and($payload['attachments'][0]['text'])->toContain('Failed during database, files');
});
