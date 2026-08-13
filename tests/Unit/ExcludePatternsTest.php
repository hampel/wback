<?php

use App\Commands\Files;

/**
 * Build the --exclude option zip would be given for these patterns.
 *
 * The command is instantiated directly - assembling the option needs no
 * application, and this is the part of the files backup most likely to be
 * edited by hand.
 */
function excludeOption(array $patterns): string
{
    $command = new class extends Files
    {
        public function excludeOption(array $patterns): string
        {
            return $this->generateExcludes($patterns);
        }
    };

    return $command->excludeOption($patterns);
}

it('returns nothing when there is nothing to exclude', function () {
    expect(excludeOption([]))->toBe('');
});

it('escapes wildcards so the shell leaves them for zip', function () {
    expect(excludeOption(['data/tmp/*']))->toBe(" --exclude 'data/tmp/*'");
});

it('escapes every wildcard in a pattern', function () {
    expect(excludeOption(['data/*/cache/*']))->toBe(" --exclude 'data/*/cache/*'");
});

it('passes multiple patterns to a single option', function () {
    expect(excludeOption(['data/tmp/*', 'internal_data/cache/*']))
        ->toBe(" --exclude 'data/tmp/*' 'internal_data/cache/*'");
});

it('keeps a pattern with spaces as a single argument', function () {
    expect(excludeOption(['data/My Documents/*']))->toBe(" --exclude 'data/My Documents/*'");
});

it('leaves patterns without wildcards alone', function () {
    expect(excludeOption(['data/tmp']))->toBe(" --exclude 'data/tmp'");
});
