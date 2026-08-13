<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the application and drive the commands through artisan.
| Every command assembles a shell command string out of config, so we pin the
| binary paths and remotes to known values here - otherwise the assertions
| would depend on whatever happens to be in the developer's .env file.
|
*/

uses(Tests\TestCase::class)
    ->beforeEach(function () {
        config()->set([
            'logging.default' => 'null',
            'backup.timezone' => 'Australia/Sydney',
            'backup.mysql.dump_binary' => '/usr/bin/mysqldump',
            'backup.mysql.default_charset' => 'utf8mb4',
            'backup.mysql.hexblob' => true,
            'backup.mysql.single_transaction' => true,
            'backup.mysql.options' => '',
            'backup.shell' => '/bin/bash',
            'backup.gzip_binary' => '/bin/gzip',
            'backup.zip_binary' => '/usr/bin/zip',
            'backup.rclone.binary' => '/usr/bin/rclone',
            'backup.rclone.cloud_remote' => 'cloud:backups',
            'backup.rclone.sync_remote' => 'sync:live',
            'backup.rclone.sync_options' => '',
            'backup.rclone.sync_allow_empty' => false,
            'backup.rclone.sync_backup_dir' => '',
            'backup.keeponly_days' => 7,
            'backup.keepleast_days' => 3,
        ]);

        Storage::fake('files');
        Storage::fake('backup');

        // destination filenames are datestamped in the application timezone
        Carbon::setTestNow(Carbon::parse('2026-08-13 12:00:00', config('app.timezone')));
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Write a sites inventory and point the application at it.
 */
function useSites(string $toml): string
{
    Storage::fake('sites');
    Storage::disk('sites')->put('wback.toml', $toml);

    $path = Storage::disk('sites')->path('wback.toml');

    config()->set('backup.sites_path', $path);

    return $path;
}

/**
 * Create a source directory on the files disk, as the site being backed up.
 */
function useSource(string $domain, array $files = ['index.php']): string
{
    foreach ($files as $file) {
        Storage::disk('files')->put("{$domain}/{$file}", 'contents');
    }

    Storage::disk('files')->makeDirectory($domain);

    return Storage::disk('files')->path($domain);
}

/**
 * The absolute path a backup would be written to.
 */
function backupPath(string $path): string
{
    return Storage::disk('backup')->path($path);
}

/**
 * A gzipped dump, as mysqldump leaves one - ending with the marker it writes when it
 * finishes, unless asked for one that stopped partway.
 */
function dumpArchive(bool $complete = true): string
{
    $sql = "-- MySQL dump 10.19\nINSERT INTO thing VALUES (1);\n";

    return gzencode($complete ? $sql . "-- Dump completed on 2026-08-13  3:00:00\n" : $sql);
}

/**
 * The command inside the pipefail wrapper.
 *
 * Pipelines are handed to a shell as a single quoted argument, so assertions read
 * against the command the operator would recognise rather than the quoting of it.
 */
function shellCommand(string $command): string
{
    $prefix = config('backup.shell') . ' -o pipefail -c ';

    if (! str_starts_with($command, $prefix)) {
        return $command;
    }

    return str_replace("'\\''", "'", substr($command, strlen($prefix) + 1, -1));
}
