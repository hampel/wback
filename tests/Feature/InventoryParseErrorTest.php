<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| An inventory that will not parse
|--------------------------------------------------------------------------
|
| The parser's message can quote text from the file - an unquoted value comes back as
| `... with value "<the value>"` - so it carries whatever was typed there. Measured on
| 2026-10-24, that text reached the console, the local log and the Slack log channel,
| which takes error records in production. The run summary was never involved, since a
| parse failure returns before any site is recorded.
|
| So the console keeps the whole message, which is what someone fixing the file needs,
| and the log gets the file and the line and nothing else. These tests hold both halves.
|
| They listen for MessageLogged rather than using Log::spy(), because the claim is that
| the value is in NO record, and a spy can only answer for the calls it is asked about.
|
*/

function recordLogs() : ArrayObject
{
    $records = new ArrayObject();

    Event::listen(MessageLogged::class, fn (MessageLogged $e) => $records->append($e));

    return $records;
}

// Everything the log would store, as text. Not json_encode() on its own: an exception in
// the context encodes as `{}`, so its message - the part that quotes the file - would be
// invisible to the check, which is the failure this file exists to catch.
function loggedText(ArrayObject $records) : string
{
    $flatten = function ($value) use (&$flatten)
    {
        return match (true)
        {
            $value instanceof \Throwable => $value->getMessage() . ' ' . $flatten($value->getPrevious()),
            is_array($value) => implode(' ', array_map($flatten, $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
    };

    $text = '';

    foreach ($records as $record)
    {
        $text .= $record->message . ' ' . $flatten($record->context) . "\n";
    }

    return $text;
}

function parseFailureRecord(ArrayObject $records) : ?MessageLogged
{
    foreach ($records as $record)
    {
        if ($record->message === 'Sites inventory could not be parsed')
        {
            return $record;
        }
    }

    return null;
}

// a typo that leaves a value unquoted, three lines in - the shape that quotes the value
const BROKEN_INVENTORY = <<<'TOML'
    [example]
    domain = 'example.com'
    secret = hunter2CANARY
    TOML;

it('logs the file and the line of a backup run\'s parse failure, and not the value', function () {
    $records = recordLogs();
    useSites(BROKEN_INVENTORY);

    $exit = Artisan::call('database', ['--all' => true]);
    $console = Artisan::output();

    expect($exit)->toBe(1);

    $record = parseFailureRecord($records);

    expect($record)->not->toBeNull()
        ->and($record->level)->toBe('error')
        ->and($record->context['line'])->toBe(3)
        ->and($record->context['sites'])->toBe(config('backup.sites_path'))
        ->and($record->context)->not->toHaveKey('exception');

    // nothing anywhere in the log has the value - not this record, not any other
    expect(loggedText($records))->not->toContain('CANARY');

    // and the console still has the whole message, which is what fixing the file needs
    expect($console)->toContain('hunter2CANARY');
});

it('logs the file and the line of an app:sites parse failure, and not the value', function () {
    $records = recordLogs();
    useSites(BROKEN_INVENTORY);

    $exit = Artisan::call('app:sites');
    $console = Artisan::output();

    expect($exit)->toBe(1);

    $record = parseFailureRecord($records);

    expect($record)->not->toBeNull()
        ->and($record->context['line'])->toBe(3)
        ->and($record->context)->not->toHaveKey('exception');

    expect(loggedText($records))->not->toContain('CANARY')
        ->and($console)->toContain('hunter2CANARY');
});

it('still logs the failure when the parser names no line', function () {
    // a duplicate key is reported without a line number, and without the value either
    $records = recordLogs();
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        domain = 'hunter2CANARY.example.com'
        TOML);

    expect(Artisan::call('database', ['--all' => true]))->toBe(1);

    $record = parseFailureRecord($records);

    expect($record)->not->toBeNull()
        ->and($record->context)->toHaveKey('line')
        ->and($record->context['line'])->toBeNull();

    expect(loggedText($records))->not->toContain('CANARY');
});
