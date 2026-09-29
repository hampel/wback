<?php

use Monolog\Formatter\JsonFormatter;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| LOG_SINGLE_FORMAT
|--------------------------------------------------------------------------
|
| The log file ships to a store that would otherwise take each line as one
| unparsed string, timestamped when it was collected rather than when it was
| written. JSON makes level, channel, context and extra into fields, and the
| datetime carries its UTC offset.
|
| Off unless asked for: LogManager::prepareHandler() tests `formatter` with
| isset(), so null is the same as absent and Laravel's own LineFormatter is
| used. Shipping the config changes nothing until the variable is set.
|
*/

/** The `single` channel config/logging.php computes for a given LOG_SINGLE_FORMAT. */
function singleChannel(?string $value): array
{
    $value === null ? putenv('LOG_SINGLE_FORMAT') : putenv("LOG_SINGLE_FORMAT={$value}");

    try
    {
        return (require base_path('config/logging.php'))['channels']['single'];
    }
    finally
    {
        putenv('LOG_SINGLE_FORMAT');
    }
}

/** The formatter it computes, alone. */
function singleFormatter(?string $value): ?string
{
    return singleChannel($value)['formatter'];
}

it('leaves the format alone unless asked, so deploying it changes nothing', function () {
    expect(singleFormatter(null))->toBeNull();
});

it('switches to json when asked', function () {
    expect(singleFormatter('json'))->toBe(JsonFormatter::class);
});

it('falls back to text on a value it does not recognise, rather than failing to boot', function () {
    expect(singleFormatter('jsonn'))->toBeNull()
        ->and(singleFormatter('JSON'))->toBeNull();
});

it('keeps stack traces, which JsonFormatter drops by default', function () {
    // JsonFormatter defaults includeStacktraces to FALSE while Laravel's LineFormatter
    // passes true, so without formatter_with every exception would keep its class and
    // message and silently lose its trace. Nothing would report that.
    $path = storage_path('json-format-test.log');
    @unlink($path);

    // taken from the config FILE rather than set here, or this would test Monolog
    // rather than wback and would pass with the formatter_with line deleted
    $channel = singleChannel('json');

    config()->set([
        'logging.channels.single' => ['path' => $path] + $channel,
    ]);

    // thrown, not constructed: getTrace() lists the CALLING stack, so an exception made
    // where nothing called it has an empty one - which reads as a missing trace
    $thrower = fn () => throw new RuntimeException('boom');

    try { $thrower(); } catch (Throwable $e) {
        Log::channel('single')->error('trace check', ['exception' => $e]);
    }

    $record = json_decode(trim(file_get_contents($path)), true);
    @unlink($path);

    expect($record['level_name'])->toBe('ERROR')
        ->and($record['datetime'])->toMatch('/[+-]\d{2}:\d{2}$/')
        ->and($record['extra'])->toHaveKey('hostname')
        ->and($record['context']['exception'])->toHaveKey('trace')
        ->and($record['context']['exception']['trace'])->not->toBeEmpty();
});
