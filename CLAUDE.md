# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`wback` is a website backup CLI built on Laravel Zero 12 (PHP 8.2+). It reads a
TOML inventory of sites and, for each one, shells out to `mysqldump`, `zip` and
`rclone` to produce and ship backups. It contains no backup logic of its own —
every command builds a shell command string and runs it.

The stock Laravel Zero README.md is upstream boilerplate and describes the
framework, not this app.

## Commands

```bash
php wback                       # default: summary list of all commands
php wback app:config            # resolved config (paths, binaries, remotes, disks, logging)
php wback app:sites [site]      # dump the parsed TOML inventory
php wback app:test              # write one log line at every level, then show logging config

php wback database <site> [-a] [-d]   # mysqldump | gzip -> backup disk
php wback files    <site> [-a] [-d]   # zip -> backup disk
php wback cloud    <site> [-a] [-d]   # rclone copy the whole backup tree to cloud_remote
php wback sync     <site> [-a] [-d]   # rclone sync configured live dirs to sync_remote
php wback clean    <site> [-a] [-d]   # delete backups older than keeponly_days

vendor/bin/pest                          # all tests
vendor/bin/pest tests/Unit/ExampleTest.php   # single file
vendor/bin/pest --filter='inspires'          # single test
php wback app:build wback                # compile a PHAR into builds/ (box.json)
```

`-a|--all` iterates every site; `-d|--dry-run` logs the command it would run
without executing it. Passing neither a site nor `--all` prints usage plus the
site list and exits FAILURE.

## Testing

Feature tests drive the commands through `$this->artisan()` with `Process::fake()`
and faked `files`/`backup` disks, and assert on the **command string** each
command assembles — that string is the product, so that is what is tested.

`tests/Pest.php` pins every binary path, remote and retention setting in a
`beforeEach`, so assertions don't depend on the developer's `.env`, and freezes
the clock (destination filenames are datestamped in the app timezone). Its
helpers: `useSites($toml)` writes an inventory and points config at it,
`useSource($domain, $files)` creates a source tree on the files disk, and
`backupPath($path)` gives the absolute destination path.

Faked disks are real directories under `storage_path()` — the commands build
shell commands out of `Storage::disk(…)->path()`, and `File::isDirectory()` needs
a real stat, so there is no in-memory option. `CreatesApplication` therefore
points the test application's storage path at a per-process directory in the
system temp dir that is removed on exit, keeping the project's `storage/` clean.

Two gotchas when adding tests:

- `expectsOutputToContain()` is greedy — a short substring expectation will
  swallow a later line that also contains it, and the more specific expectation
  then fails. Keep expectations non-overlapping, or use exact `expectsOutput()`.
- The schedule is built while the application boots, so `config()->set()` on
  `backup.schedule_start` has no effect; set `SCHEDULE_START` in the environment
  and call `$this->refreshApplication()` (see `tests/Feature/ScheduleTest.php`).

## Architecture

**BaseCommand is a template method.** `app/Commands/BaseCommand.php` owns the
whole per-site loop: parse the TOML at `config('backup.sites_path')`, resolve
either one site or all of them, require a `domain` key, and call the subclass's
`handleSite(array $site, string $name)`. `Database`, `Files`, `Cloud`, `Sync`
and `Clean` implement only `handleSite()` and `scheduleOffset()`. Throwing
`\RuntimeException` from `handleSite()` is the idiomatic way to fail a site —
`runSite()` catches it per site, logs it, and carries on to the next one, so a
`--all` run still exits FAILURE but backs up everything it can. A failed
external command arrives the same way, since Illuminate's
`ProcessFailedException` extends `RuntimeException`.

`Sites`, `Config` and `Test` extend Laravel Zero's `Command` directly and are
namespaced `app:` to keep the backup verbs at the top level.

**Two storage disks** (`config/filesystems.php`):
- `files` — source root, `FILES_ROOT`, default `/srv/www`
- `backup` — destination root, `BACKUP_DEST_PATH`, default `storage_path('backup')`

Backups land at `<backup>/<domain>/{files,database}/<shortname>.<Ymd><suffix>`,
with `-2`, `-3` … appended by `getDestinationFile()` when a file for today
already exists. Directories are created on demand; output files are chmod 0660.

**`executeCommand()` is the single choke point** for running external binaries.
It logs the command at debug level and short-circuits under `--dry-run` — except
when `$override = true`, which `Cloud` and `Sync` use because they instead
translate `--dry-run` into rclone's own `--dry-run` flag.

**`log($level, $message, $logMessage = null, $context = [])`** dual-writes: to
the Monolog channel (structured, with `$context`) and to the console (styled,
gated by a level→verbosity map, so debug lines only appear under `-vvv`). Use it
rather than `$this->info()` / `Log::info()` for anything worth recording.
`Test.php` carries its own copy of this method — a deliberate duplicate, since
it does not extend `BaseCommand`.

**Scheduling** is spread by offset, not hardcoded times. Each command returns
`scheduleOffset()` in hours (database 0, files 1, cloud 2, sync 3, clean 4) and
`getScheduleTime()` adds it to `backup.schedule_start` (default 03:00). Adding a
command means picking the next free offset.

**PHAR-aware storage path**: `bootstrap/app.php` sets the storage path to
`getcwd()` when running inside a Phar, so a compiled `wback` resolves `.env`,
`wback.toml` and the default backup/log paths relative to the working directory.
The timezone is hardcoded to `Australia/Sydney` in `config/app.php`.

## The TOML site inventory

`wback.toml` in the repo root is the documented template; the live file lives at
`SITES_TOML_PATH` (default `storage_path('wback.toml')`, gitignored). Each table
is a short name, and the defaulting rules matter:

- `domain` — required, and the directory name under the backup disk.
- `database` — omitted means "use the short name"; explicit `''` means skip the
  database entirely.
- `files` — omitted means `<FILES_ROOT>/<domain>`; explicit `''` means skip files.
- `exclude` — array of zip patterns; `*` is escaped before being passed to `zip`.
- `sync` — array of paths relative to the file source, rclone-synced live (not
  archived) to `sync_remote`.

## Conventions

- Configuration is env-driven through `config/backup.php`; `.env.example`
  documents every variable. Read config through `config()`, never `env()`
  outside `config/`.
- The code uses Allman braces and its own spacing, which is **not** Laravel/PSR-12.
  Pint is installed but there is no `pint.json`, so running it would reformat the
  entire codebase — don't run it across existing files.
- `--force` is declared on `cloud` and `sync` but never read; it is a stub.
