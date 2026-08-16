# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`wback` is a website backup CLI built on Laravel Zero 13 (PHP 8.3+). It reads a
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
php wback app:validate          # run the binaries, connect to the databases, list the remotes

php wback cron [-d]                  # every backup in turn, what cron calls

php wback database <site> [-a] [-d]   # mysqldump | gzip -> backup disk
php wback files    <site> [-a] [-d]   # zip -> backup disk
php wback cloud    <site> [-a] [-d]   # rclone copy the whole backup tree to cloud_remote
php wback sync     <site> [-a] [-d]   # rclone sync configured live dirs to sync_remote
php wback clean    <site> [-a] [-d]   # delete backups older than keeponly_days

vendor/bin/pest                          # all tests
vendor/bin/pest tests/Feature/CronCommandTest.php   # single file
vendor/bin/pest --filter='dry run'                  # single test
php wback app:build wback                # compile a PHAR into builds/ (box.json)
```

`-a|--all` iterates every site and takes precedence over a site named on the
command line; `-d|--dry-run` logs the command it would run
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
- `Process::recorded()` is not public in this version of Illuminate, so a test
  that cares about the order commands ran in has to record them from a closure
  fake — see `recordCommands()` in `tests/Feature/CronCommandTest.php`.

## Architecture

**BaseCommand is a template method.** `app/Commands/BaseCommand.php` owns the
whole per-site loop: parse the TOML at `config('backup.sites_path')`, resolve
either one site or all of them, require a `domain` key, and call the subclass's
`handleSite(array $site, string $name)`. `Database`, `Files`, `Cloud`, `Sync`
and `Clean` implement only `handleSite()`. Throwing
`\RuntimeException` from `handleSite()` is the idiomatic way to fail a site —
`runSite()` catches it per site, logs it, and carries on to the next one, so a
`--all` run still exits FAILURE but backs up everything it can. A failed
external command arrives the same way, since Illuminate's
`ProcessFailedException` extends `RuntimeException`.

`Sites`, `Config` and `Validate` extend Laravel Zero's `Command` directly and are
namespaced `app:` to keep the backup verbs at the top level. `Validate` exercises
the real thing — it runs each binary, dumps each schema to /dev/null, lists each
remote and takes the lock — so it is the command to extend when a new dependency
on the environment appears.

**Two storage disks** (`config/filesystems.php`):
- `files` — source root, `FILES_ROOT`, default `/srv/www`
- `backup` — destination root, `BACKUP_DEST_PATH`, default `storage_path('backup')`

Backups land at `<backup>/<domain>/{files,database}/<shortname>.<Ymd><suffix>`,
with `-2`, `-3` … appended by `getDestinationFile()` when a file for today
already exists. Directories are created on demand; output files are chmod 0660.

**Command strings go through a shell**, so anything interpolated into one from
the inventory or from a derived path must be wrapped in `escapeshellarg()` — the
configured binary paths and `BACKUP_MYSQLDUMP_OPTIONS` are the deliberate
exceptions, left raw so they can carry options.

**Any command containing a pipe must go through `pipeline()`**, which wraps it in
`bash -o pipefail` so a failure on the left of the pipe isn't masked by success on
the right. Tests assert against `shellCommand($process->command)`, a `tests/Pest.php`
helper that unwraps it.

**`executeCommand()` is the single choke point** for running external binaries.
It logs the command at debug level and short-circuits under `--dry-run` — except
when `$override = true`, which `Cloud` and `Sync` use because they instead
translate `--dry-run` into rclone's own `--dry-run` flag.

**`log($level, $message, $logMessage = null, $context = [])`** dual-writes: to
the Monolog channel (structured, with `$context`) and to the console (styled,
gated by a level→verbosity map, so debug lines only appear under `-vvv`). Use it
rather than `$this->info()` / `Log::info()` for anything worth recording. It
lives in the `LogsToConsole` trait, used by everything that reports. Context is
worth passing: Slack renders context and extra as fields, which is what tells one
site's failure from another's.

`app/Logging/StampHostname` is a **tap** that pushes `HostnameProcessor` onto the
`single`, `daily` and `slack` channels, stamping every record with
`logging.hostname` so one webhook can serve a fleet. It has to be a tap — the
`processors` key in a channel's config is only read by the `monolog` driver.

**Laravel Zero's scheduler is not used, and its commands are removed** in
`config/commands.php` so they cannot be run by mistake — a due event fatals on a
trait from the uninstalled `illuminate/queue`, a compiled binary hands Process a
`phar://` working directory it rejects, and `ScheduleRunCommand` reports success
either way (`null == 0`). The `schedule()` methods are commented out in place
rather than deleted. Ordering lives in `Cron::$stages` instead; a new backup
command goes in that list.

**One lock covers a whole run.** `App\Support\BackupLock` is a container
singleton wrapping an `flock`, taken by whichever command starts first — `cron`
for a scheduled run — with the commands it calls seeing `isHeld()` and leaving it
alone. That indirection is necessary: `flock` conflicts with itself when one
process opens the file twice. `LocksBackups` and `LogsToConsole` in `app/Support`
are the traits shared between `BaseCommand` and `Cron`.

**PHAR-aware storage path**: `bootstrap/app.php` sets the storage path to
`getcwd()` when running inside a Phar, so a compiled `wback` resolves `.env`,
`wback.toml` and the default backup/log paths relative to the working directory.
**Never put an `env()` call in `config/app.php`.** `app:build` evaluates that file
on the build machine and rewrites it as a literal array before compiling
(`BuildCommand::prepare()`), so the value is frozen at build time and no `.env`
beside the binary can change it. That is why the timezone lives in
`config/backup.php` as `backup.timezone`, applied over `app.timezone` by
`AppServiceProvider::boot()`. Every other config file is compiled as written.

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
