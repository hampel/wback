# Changelog

Notable changes to wback. Versions before 7.0.0 are recorded in the git history
only.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [7.6.1] - 2026-09-16

### Fixed

- An environment file that cannot be parsed now reports the file and the line
  number, and exits 1. It used to escape as an uncaught `InvalidFileException` —
  a PHP fatal, a stack trace, and exit 255 — and **the message Dotenv throws
  contains the offending line**, so a `.env` line carrying a credential was
  printed in full. A line that will not parse is exactly the kind that does: the
  documented way to give `mysqldump` a password is an option string, and an
  unquoted space in one is what breaks the parse. Under cron the whole thing was
  mailed. The line number is recovered by searching for the fragment rather than
  by printing it.

- `LOG_STACK=null` no longer breaks logging. `env()` converts the literal words
  `null`, `true`, `false` and `(null)` into PHP values before `config/logging.php`
  sees them, so the word `null` — which `.env.example` documented — arrived as PHP
  null and left the stack holding a single nameless channel. Laravel cannot build
  that, so it fell back to its emergency logger and wrote `Unable to create
  configured logger` into `laravel.log`, or threw outright where that path was not
  writable, which under cron is a failed backup run rather than a missing log line.
  Quoting did not help: `"null"`, `'null'`, `NULL` and `(null)` all arrive the same
  way. An empty `LOG_STACK`, and a stray comma in `single,,slack`, were the same
  defect and are fixed with it. A space, as in `single, slack`, now works where the
  value is quoted or exported; unquoted in a `.env` it never reaches the
  application, because Dotenv rejects the whole file on the space.

## [7.6.0] - 2026-09-10

### Changed

- **`LOG_SLACK_LEVEL` now defaults to `error`, not `critical`.** Every failure
  wback reports is an `error` record — a site that threw, a command that could
  not start, a lock already held — and nothing logs above one. So the old
  default configured a Slack channel whose only possible traffic was
  `app:validate`'s own test message: it looked configured, passed every check,
  and would have stayed silent on the night it was installed for. `critical` is
  Laravel's stock value for that channel and was inherited rather than chosen.
  **If you set `LOG_SLACK_LEVEL` explicitly you are unaffected**; if you relied
  on the default, a channel that was silent will start reporting failures, which
  is what it was for.
- **`FILES_ROOT` now defaults to `/var/www`**, where it was `/srv/www`. An
  installation that never set it and keeps its sites under `/srv/www` will fail
  every file backup after upgrading — `Source path [/var/www/<domain>] not found`
  — and `app:validate` fails the same check for each site, so a deploy that gates
  on it finds out before the first night does. See _Upgrading_.
- **The application is now called `wback`**, where it was `Website Backup`. It is
  what `--version` prints, what `app:config` reports as the name, and the footer
  of every run summary. The version is still the last field of `--version`.
- The sites inventory template in the repository is now `wback.toml.example`,
  where it was `wback.toml`.

### Added

- `app:validate` warns when `LOG_SLACK_LEVEL` is above the level anything
  actually logs at, so the misconfiguration above is visible rather than
  indistinguishable from a quiet night. It warns rather than fails, and it warns
  under `--unattended` too — the threshold is a fact about the configuration, not
  something the sweep discovers, so a deploy gate should surface it even on a run
  that posts nothing.
- The delivery line now says when the run summary test shares the log channel's
  webhook, and gives the total to expect. The two post to the same place in the
  common setup, so the count in the channel is one more than the sweep sent —
  and a bare four matched both a threshold of `error` and one of `critical`,
  which is how a channel nobody had configured on purpose passed a smoke test.

### Upgrading

- **Set `FILES_ROOT=/srv/www` before upgrading if your sites live there and you
  never set it.** This is the one change here that stops backups rather than
  altering them: database backups carry on, but every file backup fails until the
  root is right. Run `wback app:validate` after upgrading — it fails the file
  source check for every site if it is not.
- **If you relied on the `LOG_SLACK_LEVEL` default**, the slack log channel starts
  posting failures, which is what it was for. Set `LOG_SLACK_LEVEL=critical` to
  keep it quiet, and `app:validate` will warn that nothing reaches it.
- **Anything matching `Website Backup` in wback's output** — the `--version`
  banner, or the footer of a run summary — needs to match `wback` instead.

## [7.5.0] - 2026-09-06

### Added

- `app:validate --unattended`, for a run nobody is watching. Two of the checks
  are only worth their cost when someone looks at where the message landed — the
  sweep that writes a record at every log level, and the run summary test post —
  and a deploy gate fires both into an operations channel with nobody having
  asked. The flag suppresses those two and nothing else: the `rclone` remote
  checks still go out, because a remote that has stopped responding is what an
  automated gate exists to surface. Both report as skips rather than
  disappearing, and the flag is never the default — forgetting it costs some
  channel noise you can delete, whereas defaulting it on would cost every future
  run its proof of delivery, silently.
- The logging section now says how many records the Slack channel should have
  taken, and at which levels — `posted 3 records at critical and above: critical,
  alert, emergency`. The sweep is the only thing that proves the webhook URL and
  `LOG_SLACK_LEVEL` are both right, since neither can be checked from the sending
  end, and that only works if you know what number to expect. It also reports a
  slack channel sitting in the stack with no webhook to post to, which previously
  looked identical to a working one.

## [7.4.2] - 2026-08-29

### Added

- Continuous integration, and with it an automated release. `.github/workflows/ci.yml`
  runs the test suite and compiles the binary on every push and pull request; pushing
  a tag builds the artefact, generates `SHA256SUMS` and publishes the GitHub release
  with that version's changelog section as its notes. The asset names are unchanged —
  `wback-<version>` and `SHA256SUMS` — so the documented install and the pyinfra
  checksum pin both keep working. The README carries the run status as a badge.
- The two release traps are now structural rather than remembered. A tag-triggered
  build cannot compile a stale `git describe`, and the release job refuses to publish
  a binary whose `--version` does not equal the tag; the build runs `composer build`
  from a clean checkout, so a fat artefact like 7.0.0–7.4.0's cannot be produced by
  forgetting a step. The compiled binary is also smoke-tested — it runs, reports its
  own version, and exits non-zero on a mistyped command, which is the one behaviour
  the suite cannot check on the artefact that actually ships.

### Changed

- `app:validate` draws its six group headings through `hampel/console-report`'s
  new `checkSection()` (2.1.0) rather than the app's own `section()`. They move
  to the two-column margin, so a heading now lines up with the `[ ok ]` markers
  beneath it instead of hanging to their left, and the rule under it goes. The
  check rows themselves are unchanged.
- Backup runs keep the ruled cyan heading: `section()` stays in `LogsToConsole`
  for `cron` and the per-site headings, where a rule across a scrolling run log
  is doing a different job to a heading over a report you read top to bottom.

### Fixed

- An unrecognised log level passed to `log()` now renders at normal verbosity as
  intended. The fallback was the string `'warning'` where a `VERBOSITY_*` integer
  belongs, which `line()` does not recognise and quietly replaces with whatever
  verbosity was last set. Latent — every current caller passes a level the map
  knows.

## [7.4.1] - 2026-08-25

### Fixed

- The released binary no longer carries the development dependencies. `app:build`
  never runs Composer and `box.json` takes `vendor/` wholesale, so building from a
  dev checkout compiled Pint, PHPUnit, Pest and Mockery into the artefact — Pint
  alone was 21 MB of it. **The download drops from 29 MB to 6 MB**, and a
  production server stops being handed a code formatter and a test framework it
  will never run. No behaviour changes: same code, same command strings, same
  exit codes.
- 7.0.0 through 7.4.0 are all affected and are left as published, since their
  checksums are pinned and a release whose bytes change under a fixed version is
  the thing checksums exist to prevent. Upgrade to get the smaller one.

### Added

- `composer build`, which installs `--no-dev`, compiles, restores the dev
  dependencies, and then fails if `laravel/pint` is still findable in the
  artefact. That last grep is the whole guard — nothing else noticed for five
  releases.

## [7.4.0] - 2026-08-25

### Added

- `app:validate` now warns when a site's file source exists but is empty. The
  nightly refuses an empty source, so validate said `[ ok ]` about a site that
  was guaranteed to fail, and only the nightly run itself would find out. What
  validate warns about mirrors what the stage refuses, which is how the sync
  check beside it already worked; files simply never got the same treatment. It
  is a warning and not a failure, so the exit code is unchanged and a deploy
  gating on `app:validate` cannot start failing because of it.

## [7.3.1] - 2026-08-25

### Fixed

- `app:validate` no longer stops at the first check whose command will not start.
  A command that cannot be spawned at all — an unreadable working directory does
  this to every one of them — is now a failed check like any other, so the
  remaining binaries, and the paths, sites, remotes, logging and summary after
  them, are still reported. This happened in production: one run ended at
  `mysqldump` and looked at nothing else, which is the worst moment to stop
  talking for a command whose whole job is to say what is wrong.
- A check that cannot start now reports the cause rather than the first line of
  the exception. `proc_open(): posix_spawn() failed: Permission denied (working
  directory: /root)` says what to fix; `The command "…" failed.` repeats the
  check's own label. The whole message still goes to the log, with the check and
  the command as context.

### Changed

- A file backup of an empty source directory now fails as an empty source
  directory, rather than as `zip` exit code 12 and "Nothing to do!". An
  unexpectedly empty docroot is worth failing over — it is what a half-finished
  migration looks like at 03:17 — but it should say so in the site's terms and
  name the site it belongs to. A site that deliberately has nothing to archive
  still opts out with `files = ''`.

### Documentation

- The README now says that one site failing does not stop the others, which was
  the undocumented half — a failed *stage* not stopping the ones after it was
  already written down. A `Skipping [cloud]` line is only ever `--no-cloud`,
  never the consequence of an earlier failure, and that is now stated where
  someone reading a bad night's log will find it.
- The installation section no longer promises a `SHA256SUMS` on every release
  without one existing. 7.0.0, 7.1.0 and 7.2.0 have been given one after the
  fact — checked against the published assets rather than against a local build
  — so the documented check now works for every 7.x release, not just the newest.

## [7.3.0] - 2026-08-23

### Added

- A run summary: one Slack message per `cron` run, saying what the run did — sites,
  backups, bytes written, how long it took, which stages ran, and any failures
  named by site and stage. Set `BACKUP_SUMMARY_SLACK_WEBHOOK`, and
  `BACKUP_SUMMARY_NOTIFY=failure` if only the bad nights are wanted. This is what
  a log channel structurally cannot do: logging posts a record at a time, so it
  can only ever report trouble, and a run where nothing fails is silent in exactly
  the way an uninstalled cron entry is. The log channel stays as the backstop.
- A run that finished in under a second reports `<1s` rather than `0s`, which read
  as a duration nobody measured.
- A run blocked by the lock reports too — `Backup did not run`, naming the holder
  — which used to leave nothing behind but a single log line.
- `app:validate` posts a test message to the summary webhook and fails if Slack
  refuses it. A mistyped or revoked webhook is otherwise invisible until it
  matters.
- `app:config` reports the summary settings and whether the log channel's Slack
  webhook is set. Both webhooks are reported as set or not set, never printed.
- Every log record is stamped with the machine it came from, so one Slack webhook
  can serve a whole fleet instead of one per installation to tell the alerts
  apart. `LOG_HOSTNAME` sets the label and defaults to the system hostname; an
  empty value turns the stamp off. `LOG_SLACK_USERNAME` defaults to it too.
- `app:config` and `app:validate` report the hostname label.

### Changed

- The "Backup written" log entry carries the size twice: `bytes` as before, and
  `size` rounded and with a unit, so a log can be read without converting the
  large numbers by hand.
- A failed site is logged with the site, domain and stage as context, where it
  used to be the exception message alone — a failed process says which command it
  was but nothing about whose backup it belonged to.
- `app:config` and `app:validate` draw their output with
  [hampel/console-report](https://github.com/hampel/console-report) rather than
  with private copies of the same renderers. The output is unchanged, byte for
  byte, apart from the last two fixes below. The minimum is `^2.0`, whose only
  break is that the renderers are handed the command's output rather than calling
  back into it — which is what lets the same package serve consoles that are not
  Laravel.

### Fixed

- `app:config` no longer reports an environment file it never opened. With no
  `.env` in any of the places wback looks, it used to print the framework's guess
  — which inside a built binary is a `phar://` path into the read-only archive,
  indistinguishable from a real answer on the one line an operator reads first
  when a setting is not taking effect. It now names the file it actually read, or
  says `none found` and lists where it looked.
- A command name wback does not recognise is now an error that exits non-zero and
  suggests the nearest match. Laravel Zero proxies an unrecognised name to the
  default command, so `wback app:validte` printed the command list and exited 0 —
  which reads as a passing check to cron, to a deploy script, and to anything else
  that gates on `app:validate`.
- `app:config` printed every path with the project directory silently removed —
  the environment file as `.env`, the backup destination as `storage/backup` —
  because Laravel's two-column component runs its values through a mutator that
  strips `base_path()` and cannot be turned off. The rows are rendered directly
  now, and a value too long for the line wraps rather than being truncated.
- A relative path in `app:config` is reported along with the working directory it
  resolves against, and an unset remote as `not set`, rather than either passing
  for a setting that is in order.
- Anything resembling a password in `BACKUP_MYSQLDUMP_OPTIONS`,
  `BACKUP_CLOUD_OPTIONS` or `BACKUP_SYNC_OPTIONS` is redacted from `app:config`,
  which is the output that gets pasted into support tickets. It covers the usual
  flag spellings; credentials still belong in a defaults file.
- An empty `BACKUP_SHELL` — a legitimate setting, meaning "run pipelines under
  the system shell" — is reported as `none` by `app:config`. It used to render as
  a line of dots indistinguishable from a section heading.
- A check row in `app:validate` with no detail no longer ends in trailing spaces.
- `app:config` no longer reports a `phar://` path as relative to the working
  directory. A stream wrapper URI locates a resource outright, so there is nothing
  for it to be relative to — and inside a built binary `base_path()` is one, which
  is where the environment file lands when none of the places wback looks for it
  has one. Fixed upstream in `hampel/console-report` 1.0.1, and carried forward
  unchanged in the 2.0 this now requires.

### Documentation

- What happens when `cloud` and `sync` share one rclone remote: the branches they
  write to, and the single case where a directory of your own in a site's backup
  root ends up inside the sync destination and is deleted by the next sync.
- The run summary: how to read one, when it is sent, and what a run that never
  started looks like.
- The installation instructions no longer name one particular release, and the
  checksum step now works. It downloaded the binary under a different name from
  the one `SHA256SUMS` lists, so `sha256sum -c` could not find the file it was
  meant to be checking — and it ran after the install rather than before it.

### Upgrading

- **Nothing is required.** Every change below is either invisible or opt-in.
- **To turn the run summary on**, set `BACKUP_SUMMARY_SLACK_WEBHOOK`. It is off
  until you do, and the `slack` log channel is unaffected either way — the two
  are complementary, and `config/backup.php` says why.
- **`app:validate` now posts.** It writes a message at every log level and, if a
  summary webhook is configured, sends a test message to it. That is deliberate —
  a revoked webhook is otherwise invisible from the sending end — but it means
  running it puts messages in whatever channel this installation reports to.
- **A mistyped command now fails.** `wback app:validte` used to print the command
  list and exit 0. Anything that gates on an exit code will start seeing a typo it
  had been passing.

## [7.2.0] - 2026-08-14

### Added

- A `port` key for sites whose database is not on the default port.
- `cron` can skip individual stages: `--no-database`, `--no-files`, `--no-cloud`,
  `--no-sync`, `--no-clean`. For backing up locally on a machine whose cloud
  credentials are not configured yet, or never will be. A stage that is meant to
  run and cannot still fails the run.
- The environment file can live somewhere other than beside the binary: at
  `/etc/wback/.env`, or wherever `WBACK_ENV` points. A binary on the path can now
  take its configuration from `/etc/wback` and be run from anywhere.
- `app:config` reports which environment file was read.

### Changed

- **The default timezone is now `UTC`**, where it was `Australia/Sydney` — a
  default inherited from the machine this was written on rather than one that
  suits anybody else. Set `APP_TIMEZONE` to keep the old behaviour; see
  _Upgrading_.

### Fixed

- `app:validate` reports the error from a failed command rather than a warning
  printed ahead of it, which was hiding the reason for the failure.
- `LARAVEL_STORAGE_PATH` now works when set in the environment file. It was read
  before the file was loaded, so it only ever worked as a real environment
  variable.

### Documentation

- `mariadb-dump` works as the dump binary; MariaDB 11 renamed its clients and
  keeps `mysqldump` as a symlink.
- Corrected what the working directory decides: the storage path is only ever a
  default for the sites file, the backup destination and the log, so setting
  those absolutely makes the working directory irrelevant.

### Upgrading

- **Set `APP_TIMEZONE` before upgrading if you were relying on the old default.**
  Datestamps in backup filenames follow this setting, so a site backing up at
  3am in Sydney will start naming its files with the previous day's date under
  UTC. Existing backups are not touched, and `clean` expires by modification
  time rather than by the name, so nothing is lost either way — but the names
  will step back a day at the changeover.

## [7.1.0] - 2026-08-13

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
