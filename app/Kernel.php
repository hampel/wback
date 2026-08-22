<?php

namespace App;

use LaravelZero\Framework\Kernel as BaseKernel;

/**
 * A mistyped command must fail, not print the command list and exit 0.
 *
 * Laravel Zero proxies anything it cannot resolve to the default command, so that
 * `wback --some-option` works for a single-command application. The cost is that
 * `wback app:validte` finds no such command, silently becomes the summary, prints
 * the command list and exits 0 - indistinguishable, to anything reading the exit
 * code, from a successful run.
 *
 * That matters here more than most: this is a cron tool. app:validate exists so a
 * provisioning run can gate on it, and the cron entry is read by nothing but its
 * exit status. A typo in either position must be loud. It is the same failure mode
 * as the scheduler documented in config/commands.php - a command that reports
 * success having done nothing at all.
 *
 * So the proxying is kept for what it is actually for - a bare invocation, or one
 * carrying only options - and a first argument naming nothing is left to Symfony,
 * which raises CommandNotFoundException, suggests the nearest match and exits 1.
 */
class Kernel extends BaseKernel
{
    protected function ensureDefaultCommand($input) : void
    {
        // a first argument is a command name, and naming one that does not exist is
        // an error; anything else - no arguments at all, or only options - is what
        // the default command is there to catch
        if ($input->getFirstArgument() === null)
        {
            parent::ensureDefaultCommand($input);
        }
    }
}
