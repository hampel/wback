# Changelog

Notable changes to wback. Versions before 7.0.0 are recorded in the git history
only.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Upgraded to Laravel Zero 13 (Laravel components 13.x), which raises the minimum
  PHP version to **8.3**. Nothing in the application changed: the suite passes
  untouched, and a compiled binary still reads `APP_TIMEZONE` from the `.env`
  beside it.

## [7.0.0] - 2026-08-13

A release about trusting the backups: several ways they could fail quietly are
now loud, and the things that made them quiet are gone.

**This release breaks existing installations.** See _Upgrading_ below.

### Added

- `cron` command, running every backup in turn from a single cron entry, each
  stage starting when the one before it has finished.
- `app:validate`, replacing `app:test`: runs every configured binary, dumps every
  schema to `/dev/null` through the same credentials the backup uses, lists every
  remote, walks the sites file, checks the destination and takes the lock. Exits
  non-zero if anything failed, so it works as a post-deploy check.
- Dumps are read back and checked for the marker mysqldump writes when it
  finishes, catching a dump that exits 0 having written half a database
  (`BACKUP_MYSQLDUMP_VERIFY`, per site `verify`).
- `--single-transaction` on dumps, so a backup no longer read-locks every table
  in the database for as long as the dump and compression take
  (`BACKUP_MYSQLDUMP_SINGLE_TRANSACTION`, per site `single_transaction`).
- A floor under retention, keeping the most recent days of backups whatever their
  age, so a run of failures can no longer expire the last ones that worked
  (`BACKUP_KEEPLEAST_DAYS`).
- A lock, so two backup runs cannot overlap and `cloud` cannot upload an archive
  that `files` is still writing (`BACKUP_LOCK_FILE`).
- `sync` refuses a source directory that has become empty, which is what an
  unmounted filesystem looks like and would otherwise empty the remote copy
  (`BACKUP_SYNC_ALLOW_EMPTY`).
- `BACKUP_SYNC_BACKUP_DIR`, moving everything `sync` would replace or delete into
  a dated directory on the remote instead of destroying it.
- Passthrough options for the tools: `BACKUP_MYSQLDUMP_OPTIONS` (per site
  `options`), `BACKUP_CLOUD_OPTIONS`, `BACKUP_SYNC_OPTIONS`.
- `BACKUP_SHELL`, the shell used for the dump pipeline, which needs `pipefail`.
- `APP_TIMEZONE` now works, including in a built binary.
- The size of each backup is logged, with the byte count in the log context.
- A test suite (126 tests), a README describing the whole tool, and a LICENSE
  file for the MIT license the package metadata was already claiming.

### Changed

- `--all` takes precedence over a site named on the command line, and reports the
  ignored argument. Previously the site argument won and `--all` was ignored.
- A site that fails no longer stops the rest of an `--all` run; failures are
  reported per site and the run still exits non-zero.
- A failed backup command has its partial output file removed.
- Values from the site inventory are quoted before they reach the shell, so
  spaces and punctuation in names, paths and patterns are safe. Configured binary
  paths are deliberately still inserted as written.
- `cloud` and `sync` report with `--stats-one-line --stats 1m` when the output is
  not a terminal, rather than a progress display that redraws itself into your
  log files.
- `app:sites` shows settings that are deliberately turned off, and prints
  booleans as `true`/`false` rather than `1` and nothing.
- The package identifies itself as `hampel/wback` rather than the Laravel Zero
  skeleton it grew from.

### Fixed

- A `mysqldump` that failed was masked by the `gzip` it was piped into, which
  compressed the partial output and exited 0 — recording a truncated dump as a
  successful backup. Pipelines now run under a shell with `pipefail`.
- A dry run created the destination directories and warned that it could not
  change the permissions of the file it had declined to write.
- Backups failed outright with `The provided cwd … does not exist` on any
  checkout without a `storage` directory.
- The timezone was frozen at build time in a compiled binary, because `app:build`
  evaluates `config/app.php` and compiles the result in as literals. The setting
  moved to `config/backup.php`, which is compiled as written.

### Removed

- Laravel Zero's scheduler, and the `schedule:run`, `schedule:list` and
  `schedule:finish` commands, which cannot run these commands at all: a due event
  needs a trait from a package console applications do not install, a compiled
  binary hands Symfony Process a `phar://` working directory it rejects, and
  `ScheduleRunCommand` reports success either way. Use `cron`.
- `SCHEDULE_START`, with scheduling.
- `app:test`, replaced by `app:validate`.
- The `--force` option on `cloud` and `sync`, left over from a hand-rolled
  transfer that tracked its own last run time.

### Upgrading

- **Change your cron entries.** Anything calling `wback schedule:run` will now
  fail rather than silently do nothing. Use one entry calling `wback cron
  --quiet`, or keep an entry per command. See the README.
- **`app:test` is now `app:validate`**, and worth running once on each server
  after upgrading — it checks everything this release added.
- **Expect new failures on real problems.** A truncated dump, a sync source that
  has gone empty, and a backup that starts while another is still running now
  report failure rather than passing quietly. That is the point of the release,
  but it does mean a formerly green run can go red.
- **Check `single_transaction` for any site with MyISAM tables holding data you
  need consistent.** Dumps now come from an InnoDB snapshot by default.
- **Remove `SCHEDULE_START`** from your `.env`; it is no longer read.
- **If you set `APP_TIMEZONE` and it was being ignored**, it now takes effect —
  which can move the date in backup filenames.
