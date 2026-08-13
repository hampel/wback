<?php

use Illuminate\Console\Scheduling\Schedule;

/**
 * Map each scheduled backup command to its cron expression.
 */
function scheduledCommands(): Illuminate\Support\Collection
{
    return collect(app(Schedule::class)->events())
        ->mapWithKeys(function ($event) {
            preg_match('/ (\w+) --quiet --all$/', $event->command, $matches);

            return [$matches[1] ?? $event->command => $event->getExpression()];
        });
}

it('runs each backup command an hour apart from the configured start time', function () {
    expect(scheduledCommands()->all())->toEqual([
        'database' => '0 3 * * *',
        'files' => '0 4 * * *',
        'cloud' => '0 5 * * *',
        'sync' => '0 6 * * *',
        'clean' => '0 7 * * *',
    ]);
});

it('moves the whole run when the start time changes', function () {
    // the schedule is built while the application boots, so the start time has
    // to be in the environment before we can see it take effect
    putenv('SCHEDULE_START=1');
    $this->refreshApplication();

    expect(scheduledCommands()->all())->toEqual([
        'database' => '0 1 * * *',
        'files' => '0 2 * * *',
        'cloud' => '0 3 * * *',
        'sync' => '0 4 * * *',
        'clean' => '0 5 * * *',
    ]);
})->after(fn () => putenv('SCHEDULE_START'));

it('wraps around midnight rather than scheduling an impossible hour', function () {
    putenv('SCHEDULE_START=22');
    $this->refreshApplication();

    expect(scheduledCommands()->all())->toEqual([
        'database' => '0 22 * * *',
        'files' => '0 23 * * *',
        'cloud' => '0 0 * * *',
        'sync' => '0 1 * * *',
        'clean' => '0 2 * * *',
    ]);
})->after(fn () => putenv('SCHEDULE_START'));

it('produces a schedule cron can actually run', function () {
    putenv('SCHEDULE_START=22');
    $this->refreshApplication();

    collect(app(Schedule::class)->events())
        ->each(fn ($event) => expect(fn () => new Cron\CronExpression($event->getExpression()))->not->toThrow(Exception::class));
})->after(fn () => putenv('SCHEDULE_START'));

it('runs every scheduled command quietly against all sites', function () {
    collect(app(Schedule::class)->events())
        ->each(fn ($event) => expect($event->command)->toContain('--quiet --all'));
});
