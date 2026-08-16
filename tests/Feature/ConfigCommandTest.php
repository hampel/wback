<?php

/*
 * app:config answers "which files is this installation actually using", so the paths
 * in it have to survive the trip to the console intact. Laravel's twoColumnDetail
 * component strips base_path() out of every value it renders, which turned the
 * environment file into `.env` and the backup destination into `storage/backup` - both
 * plausible enough as relative paths that nothing looked wrong.
 */

it('prints paths in full, however deep in the project they are', function () {
    $path = base_path('storage/wback.toml');

    config()->set('backup.sites_path', $path);

    $this->artisan('app:config', ['--only' => 'backup'])
        ->expectsOutputToContain($path)
        ->assertSuccessful();
});

it('prints the environment file it read in full', function () {
    $this->artisan('app:config', ['--only' => 'backup'])
        ->expectsOutputToContain($this->app->environmentFilePath())
        ->assertSuccessful();
});

it('says what a relative path resolves against', function () {
    // relative paths resolve against the working directory, which under cron is
    // wherever the crontab last changed to
    config()->set('logging.channels.single.path', 'wback.log');

    $this->artisan('app:config', ['--only' => 'logging'])
        ->expectsOutputToContain('wback.log (relative to ' . getcwd() . ')')
        ->assertSuccessful();
});

it('reports a remote that is not configured yet', function () {
    config()->set('backup.rclone.cloud_remote', '');

    // one expectation, not two: expectsOutputToContain() consumes the line it matches,
    // so a second expectation against the same line has nothing left to match
    $this->artisan('app:config', ['--only' => 'backup'])
        ->expectsOutputToContain('not set')
        ->assertSuccessful();
});

it('leaves a remote alone rather than treating it as a path', function () {
    config()->set('backup.rclone.cloud_remote', 'cloud:backups');

    $this->artisan('app:config', ['--only' => 'backup'])
        ->doesntExpectOutputToContain('cloud:backups (relative to')
        ->assertSuccessful();
});

it('keeps the lock file fallback as the description it is', function () {
    config()->set(['backup.lock_file' => null, 'logging.channels.single.path' => '/var/log/wback.log']);

    $this->artisan('app:config')
        ->expectsOutputToContain('backup destination')
        ->doesntExpectOutputToContain('relative to')
        ->assertSuccessful();
});

it('shows only the section asked for', function () {
    $this->artisan('app:config', ['--only' => 'logging'])
        ->expectsOutputToContain('Logging')
        ->doesntExpectOutputToContain('Sites Path')
        ->assertSuccessful();
});

it('wraps a value that will not fit rather than cutting it off', function () {
    $path = '/mnt/' . str_repeat('backup-volume/', 12) . 'wback.toml';

    config()->set('backup.sites_path', $path);

    $this->artisan('app:config', ['--only' => 'backup'])
        ->expectsOutputToContain($path)
        ->assertSuccessful();
});

it('does not print a password that was put in the options', function () {
    // this output is what gets pasted into a support ticket
    config()->set([
        'backup.mysql.options' => '--password=hunter2 --skip-comments',
        'backup.rclone.sync_options' => '--sftp-pass=hunter2',
    ]);

    $this->artisan('app:config', ['--only' => 'backup'])
        ->doesntExpectOutputToContain('hunter2')
        ->assertSuccessful();
});

it('leaves the options that are not secrets readable', function () {
    config()->set('backup.mysql.options', '--skip-comments --max-allowed-packet=512M');

    $this->artisan('app:config', ['--only' => 'backup'])
        ->expectsOutputToContain('--skip-comments --max-allowed-packet=512M')
        ->assertSuccessful();
});
