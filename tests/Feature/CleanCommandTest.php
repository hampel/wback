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

it('deletes backups older than the retention period', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    agedBackup('example.com/files/example.20260801.zip', 12);
    agedBackup('example.com/database/example.20260801.sql.gz', 12);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('example.com/files/example.20260801.zip'))->toBeFalse()
        ->and(Storage::disk('backup')->exists('example.com/database/example.20260801.sql.gz'))->toBeFalse();
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

    agedBackup('other.example.com/files/other.20260801.zip', 12);

    $this->artisan('clean', ['site' => 'example'])->assertSuccessful();

    expect(Storage::disk('backup')->exists('other.example.com/files/other.20260801.zip'))->toBeTrue();
});

it('deletes nothing on a dry run', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

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

    agedBackup('zabbix.example.com/files/zabbix.20260801.zip', 12);
    agedBackup('zabbix.example.com/database/zabbix.20260801.sql.gz', 12);

    $this->artisan('clean', ['site' => 'zabbix'])
        ->expectsOutputToContain('No database source specified for zabbix')
        ->assertSuccessful();

    expect(Storage::disk('backup')->exists('zabbix.example.com/files/zabbix.20260801.zip'))->toBeFalse()
        ->and(Storage::disk('backup')->exists('zabbix.example.com/database/zabbix.20260801.sql.gz'))->toBeTrue();
});
