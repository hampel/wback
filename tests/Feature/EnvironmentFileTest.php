<?php

/*
|--------------------------------------------------------------------------
| An unparseable environment file
|--------------------------------------------------------------------------
|
| Dotenv puts the offending line into its exception message, and a line that
| will not parse is exactly the kind that carries a credential: the documented
| way to pass mysqldump a password is an option string, and an unquoted space in
| one is what breaks the parse. Left uncaught it reaches stderr as a fatal with
| a stack trace and exit 255 - and under cron, gets mailed.
|
| Driven as a subprocess on purpose: bootstrap/app.php exits, so an in-process
| test would take the suite with it.
|
*/

/** Run the CLI against an environment file, returning [exit code, output]. */
function runWithEnvFile(string $contents): array
{
    $path = tempnam(sys_get_temp_dir(), 'wback-env-');
    file_put_contents($path, $contents);

    $command = 'WBACK_ENV=' . escapeshellarg($path)
        . ' ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(base_path('wback'))
        . ' app:config 2>&1';

    exec($command, $output, $status);
    unlink($path);

    return [$status, implode("\n", $output)];
}

it('names the file and the line, and prints neither the value nor a stack trace', function () {
    [$status, $output] = runWithEnvFile(
        "# a comment\nBACKUP_MYSQLDUMP_OPTIONS=--password=hunter2 --single-transaction\n"
    );

    expect($status)->toBe(1)
        ->and($output)->toContain('could not parse the environment file')
        ->and($output)->toContain('at line 2')
        ->and($output)->not->toContain('hunter2')
        ->and($output)->not->toContain('Stack trace')
        ->and($output)->not->toContain('InvalidFileException');
});

it('still runs normally on a file that parses', function () {
    [$status, $output] = runWithEnvFile("BACKUP_KEEPONLY_DAYS=9\n");

    expect($status)->toBe(0)
        ->and($output)->toContain('Keep Only Days')
        ->and($output)->toContain('9');
});
