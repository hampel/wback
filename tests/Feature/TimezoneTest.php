<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * The timezone is configured in config/backup.php rather than config/app.php, because
 * app:build evaluates config/app.php on the build machine and compiles the result in as
 * literals - an env() call there is frozen at build time, and no .env beside the built
 * binary can change it. These tests pin the arrangement that works instead.
 */

it('applies the configured timezone to the application', function () {
    putenv('APP_TIMEZONE=America/New_York');
    $this->refreshApplication();

    expect(config('backup.timezone'))->toBe('America/New_York')
        ->and(config('app.timezone'))->toBe('America/New_York')
        ->and(date_default_timezone_get())->toBe('America/New_York');
})->after(fn () => putenv('APP_TIMEZONE'));

it('falls back to the configured default', function () {
    $this->refreshApplication();

    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
});

it('datestamps backup filenames in that timezone', function () {
    // the same instant is still the 13th in Sydney and already the 12th in New York
    putenv('APP_TIMEZONE=America/New_York');
    $this->refreshApplication();

    Process::fake();
    Storage::fake('backup');

    Carbon::setTestNow(Carbon::parse('2026-08-13 12:00:00', 'Australia/Sydney'));

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(
        shellCommand($process->command),
        'example.20260812.sql.gz'
    ));
})->after(fn () => putenv('APP_TIMEZONE'));

it('datestamps in the timezone the suite pins, not whatever the application booted with', function () {
    // tests/Pest.php pins backup.timezone in a beforeEach, which runs AFTER the
    // application has booted. AppServiceProvider copies the value into app.timezone
    // at boot, so code reading app.timezone never sees the pin - a developer whose
    // .env sets APP_TIMEZONE would have the suite datestamp in their zone instead.
    // Kiritimati is UTC+14: 11:00 UTC is already the 14th there and in no other zone.
    config()->set('backup.timezone', 'Pacific/Kiritimati');

    Process::fake();
    Storage::fake('backup');

    Carbon::setTestNow(Carbon::parse('2026-08-13 11:00:00', 'UTC'));

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('database', ['site' => 'example'])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(
        shellCommand($process->command),
        'example.20260814.sql.gz'
    ));
});

/*
 * The copy in app.timezone is made once, at boot. Code reading it instead of
 * backup.timezone works in production and silently ignores the suite's pin - the
 * failure the test above catches for one command path, and this catches for all.
 * The provider's own write is config([...]) and does not match.
 */
it('reads the timezone from backup.timezone, never from the copy in app.timezone', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file)
    {
        if ($file->getExtension() !== 'php')
        {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (preg_match_all("/config\(\s*'app\.timezone'\s*\)|->get\(\s*'app\.timezone'/", $source, $m, PREG_OFFSET_CAPTURE))
        {
            foreach ($m[0] as [$text, $offset])
            {
                $offenders[] = basename($file->getPathname()) . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Reads app.timezone: ' . implode(', ', $offenders)
        . ' - read backup.timezone instead; the copy is only made at boot.');
});

