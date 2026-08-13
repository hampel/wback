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
