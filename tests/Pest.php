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
            'backup.mysql.dump_binary' => '/usr/bin/mysqldump',
            'backup.mysql.default_charset' => 'utf8mb4',
            'backup.mysql.hexblob' => true,
            'backup.gzip_binary' => '/bin/gzip',
            'backup.zip_binary' => '/usr/bin/zip',
            'backup.rclone.binary' => '/usr/bin/rclone',
            'backup.rclone.cloud_remote' => 'cloud:backups',
            'backup.rclone.sync_remote' => 'sync:live',
            'backup.keeponly_days' => 7,
            'backup.schedule_start' => 3,
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
