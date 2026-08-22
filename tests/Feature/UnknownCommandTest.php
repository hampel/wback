<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Laravel Zero proxies an unrecognised command name to the default command, which
 * makes a typo print the command list and exit 0. App\Kernel keeps the proxying for
 * the bare and options-only invocations it is meant for, and lets a bad command name
 * reach Symfony - so the four cases here are the whole of that behaviour.
 *
 * These go through Kernel::handle() rather than $this->artisan(), because artisan()
 * calls the command directly and never passes through the proxying at all.
 */

function runArgv(array $arguments): array
{
    $output = new BufferedOutput;

    $status = app(Kernel::class)->handle(new ArgvInput(['wback', ...$arguments]), $output);

    return [$status, $output->fetch()];
}

it('fails on a command name that does not exist', function () {
    [$status, $output] = runArgv(['not-a-real-command']);

    expect($status)->toBe(1);
    expect($output)->toContain('not-a-real-command');
    expect($output)->toContain('is not defined');
});

it('suggests the nearest command when one is mistyped', function () {
    [$status, $output] = runArgv(['app:validte']);

    expect($status)->toBe(1);
    expect($output)->toContain('app:validate');
});

it('still shows the summary when given no arguments at all', function () {
    [$status, $output] = runArgv([]);

    expect($status)->toBe(0);
    expect($output)->toContain('USAGE:');
});

it('still proxies options with no command name to the default command', function () {
    [$status, $output] = runArgv(['--version']);

    expect($status)->toBe(0);
    expect($output)->toContain(config('app.name'));
});
