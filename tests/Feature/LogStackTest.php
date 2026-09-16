<?php

/*
|--------------------------------------------------------------------------
| LOG_STACK, and the words env() eats
|--------------------------------------------------------------------------
|
| `env()` converts the literal words null, true, false and (null) into PHP
| values, so LOG_STACK=null arrives as null rather than as the name of the null
| channel. Left alone that puts one nameless channel in the stack, which Laravel
| cannot build - it falls back to the emergency logger, or throws where that path
| is not writable. Quoting does not help: Dotenv strips the quotes first.
|
| The stack is computed when config/logging.php is evaluated, so config() holds
| only what the process started with. These re-evaluate the file instead.
|
*/

/** The stack channels config/logging.php computes for a given LOG_STACK. */
function stackChannels(?string $value): array
{
    $value === null ? putenv('LOG_STACK') : putenv("LOG_STACK={$value}");

    try
    {
        return (require base_path('config/logging.php'))['channels']['stack']['channels'];
    }
    finally
    {
        putenv('LOG_STACK');
    }
}

it('reads the null channel from the word env() turns into null', function () {
    expect(stackChannels('null'))->toBe(['null']);
});

it('falls back to the null channel when nothing is set', function () {
    expect(stackChannels(''))->toBe(['null'])
        ->and(stackChannels(null))->toBe(['null']);
});

it('keeps a real stack intact', function () {
    expect(stackChannels('single,slack'))->toBe(['single', 'slack']);
});

it('tolerates spaces and stray commas, which would name channels that do not exist', function () {
    expect(stackChannels('single, slack'))->toBe(['single', 'slack'])
        ->and(stackChannels('single,,slack'))->toBe(['single', 'slack']);
});

it('never yields a nameless channel, whatever it is given', function () {
    foreach (['null', '(null)', 'NULL', 'true', 'false', '', ',', ' , '] as $value)
    {
        expect(stackChannels($value))->not->toContain('')
            ->and(stackChannels($value))->not->toBeEmpty();
    }
});
