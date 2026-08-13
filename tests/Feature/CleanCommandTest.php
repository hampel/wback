<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Write a backup file and backdate it by the given number of days.
 */
function agedBackup(string $path, int $days): string
{
    Storage::disk('backup')->put($path, 'backup');

    touch(backupPath($path), Carbon::now()->subDays($days)->timestamp);

    return $path;
}

/**
 * Backups from the last few days, covering the floor that protects the newest days -
 * a site backing up normally - so that the age rule can be seen doing its work.
 */
function backupsUpToDate(string $directory, string $suffix, int $days = 3): void
{
    for ($day = 0; $day < $days; $day++) {
        $date = Carbon::now()->subDays($day)->format('Ymd');

        agedBackup("{$directory}/example.{$date}{$suffix}", $day);
    }
}

it('deletes backups older than the retention period', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    backupsUpToDate('example.com/files', '.zip');
    backupsUpToDate('example.com/database', '.sql.gz');

    agedBackup('example.com/files/example.20260801.zip', 12);
    agedBackup('example.com/database/example.20260801.sql.gz', 12);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260801.zip'))->toBeFalse()
        ->and(Storage::disk('backup')->exists('example.com/database/example.20260801.sql.gz'))->toBeFalse();
});

it('keeps the last backups that worked, however old they are', function () {
    // nothing has backed up for months, and expiring by age alone would leave nothing
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    agedBackup('example.com/files/example.20260501.zip', 104);
    agedBackup('example.com/database/example.20260501.sql.gz', 104);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260501.zip'))->toBeTrue()
        ->and(Storage::disk('backup')->exists('example.com/database/example.20260501.sql.gz'))->toBeTrue();
});

it('counts days rather than files, so snapshots taken together are one day of cover', function () {
    config()->set('backup.keepleast_days', 1);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    agedBackup('example.com/files/example.20260801.zip', 12);
    agedBackup('example.com/files/example.20260801-2.zip', 12);
    agedBackup('example.com/files/example.20260801-3.zip', 12);
    agedBackup('example.com/files/example.20260725.zip', 19);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260801.zip'))->toBeTrue()
        ->and(Storage::disk('backup')->exists('example.com/files/example.20260801-2.zip'))->toBeTrue()
        ->and(Storage::disk('backup')->exists('example.com/files/example.20260801-3.zip'))->toBeTrue()
        ->and(Storage::disk('backup')->exists('example.com/files/example.20260725.zip'))->toBeFalse();
});

it('expires strictly by age when the floor is turned off', function () {
    config()->set('backup.keepleast_days', 0);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    agedBackup('example.com/files/example.20260501.zip', 104);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260501.zip'))->toBeFalse();
});

it('applies the floor to each site and backup type separately', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    // files kept backing up, the database has not worked in weeks
    backupsUpToDate('example.com/files', '.zip');
    agedBackup('example.com/files/example.20260801.zip', 12);
    agedBackup('example.com/database/example.20260801.sql.gz', 12);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260801.zip'))->toBeFalse()
        ->and(Storage::disk('backup')->exists('example.com/database/example.20260801.sql.gz'))->toBeTrue();
});

it('keeps backups inside the retention period', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    agedBackup('example.com/files/example.20260810.zip', 3);
    agedBackup('example.com/database/example.20260810.sql.gz', 3);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260810.zip'))->toBeTrue()
        ->and(Storage::disk('backup')->exists('example.com/database/example.20260810.sql.gz'))->toBeTrue();
});

it('honours the configured retention period', function () {
    config()->set('backup.keeponly_days', 30);

    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    agedBackup('example.com/files/example.20260801.zip', 12);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260801.zip'))->toBeTrue();
});

it('leaves other sites alone', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'

        [other]
        domain = 'other.example.com'
        TOML);

    // the other site is backing up normally, so only the site argument protects it
    backupsUpToDate('other.example.com/files', '.zip');
    agedBackup('other.example.com/files/other.20260801.zip', 12);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('other.example.com/files/other.20260801.zip'))->toBeTrue();
});

it('deletes nothing on a dry run', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    backupsUpToDate('example.com/files', '.zip');
    agedBackup('example.com/files/example.20260801.zip', 12);

    $this->artisan('clean', ['site' => 'example', '--dry-run' => true])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260801.zip'))->toBeTrue();
});

it('skips the database directory for sites with no database', function () {
    useSites(<<<'TOML'
        [zabbix]
        domain = 'zabbix.example.com'
        database = ''
        TOML);

    backupsUpToDate('zabbix.example.com/files', '.zip');
    backupsUpToDate('zabbix.example.com/database', '.sql.gz');

    agedBackup('zabbix.example.com/files/zabbix.20260801.zip', 12);
    agedBackup('zabbix.example.com/database/zabbix.20260801.sql.gz', 12);

    $this->artisan('clean', ['site' => 'zabbix'])
        ->expectsOutputToContain('No database source specified for zabbix')
        ->assertSuccessful();

    expect(Storage::disk('backup')->exists('zabbix.example.com/files/zabbix.20260801.zip'))->toBeFalse()
        ->and(Storage::disk('backup')->exists('zabbix.example.com/database/zabbix.20260801.sql.gz'))->toBeTrue();
});
