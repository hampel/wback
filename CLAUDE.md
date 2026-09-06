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
php wback app:validate [--unattended]   # run the binaries, connect to the databases, list the remotes

php wback cron [-d]                  # every backup in turn, what cron calls

php wback database <site> [-a] [-d]   # mysqldump | gzip -> backup disk
php wback files    <site> [-a] [-d]   # zip -> backup disk
php wback cloud    <site> [-a] [-d]   # rclone copy the whole backup tree to cloud_remote
php wback sync     <site> [-a] [-d]   # rclone sync configured live dirs to sync_remote
php wback clean    <site> [-a] [-d]   # delete backups older than keeponly_days

vendor/bin/pest                          # all tests
vendor/bin/pest tests/Feature/CronCommandTest.php   # single file
vendor/bin/pest --filter='dry run'                  # single test
composer build                           # compile a release PHAR into builds/ (box.json)
php wback app:build wback                # compile in place - includes dev deps, see Releasing
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

**Nothing in the suite may send.** `tests/Pest.php` pins `logging.default` to `null`
and `backup.summary.slack_webhook` to `''` so a developer whose `.env` carries a real
webhook does not have the suite post to it, and `tests/Feature/NoLiveSendsTest.php`
fails if either pin is deleted. A test that deliberately sets a live-looking summary
webhook calls `fakeSlack()`, which swaps the PSR-18 client in the container. That does
**not** cover a log channel: Monolog's slack driver builds its own curl, so
`fakeSlack()` is not in that path at all and a `hooks.slack.com` URL on
`logging.channels.slack.url` would reach the real internet. Use `hooks.slack.test`
there — a reserved TLD that cannot resolve — which `NoLiveSendsTest` also enforces.
Sibling tools have leaked this way; the failure is silent, because nothing throws.

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

**Do not report paths with `$this->components->twoColumnDetail()`.** Its
`EnsureRelativePaths` mutator strips `base_path().'/'` out of every value and
cannot be opted out of, so absolute paths print as convincing relative ones.
`hampel/console-report` exists for this reason and `Config` and `Validate` use
it: `ReportsSettings` + `FormatsValues` draw the settings dump, `RendersChecks`
draws the `[ ok ]` / `[warn]` / `[fail]` rows, their `checkSection()` headings, and
owns the exit code. Those traits are strict about their argument types — an `int`
from config has to be cast at the call site, which is why `Keep Only Days` carries
a `(string)`. What to report stays here; only the drawing moved.

Since 2.0 the package imports no Illuminate symbol, so it has to be handed
somewhere to write before it renders anything: `setReportOutput($this->getOutput())`
at the top of `handle()`, which is what both commands do. Forget it in a third
consumer and the first render throws a `LogicException` naming the missing call —
loud, and the feature tests catch it.

**Two heading styles, deliberately.** `app:validate` heads its groups with
`checkSection()` — green, at the two-column margin so it lines up with the `[ ok ]`
markers, no rule. Backup runs head theirs with `LogsToConsole::section()` — cyan,
unmargined, with a rule and air on both sides. They are not an inconsistency to
tidy up: a rule across a scrolling run log marks a stage boundary, which is what
you scan a cron log for; a heading over a report you read top to bottom just
groups rows. Converting the rest would also mean putting `RendersChecks` — and
`checkFail()`, `$checkFailed`, `checkExitCode()` and the rest — on every command
extending `BaseCommand`, none of which check anything.
`tests/Feature/ValidateCommandTest.php` has one assertion on the validate margin;
nothing else in the suite is structural.

`Sites`, `Config` and `Validate` extend Laravel Zero's `Command` directly and are
namespaced `app:` to keep the backup verbs at the top level. `Validate` exercises
the real thing — it runs each binary, dumps each schema to /dev/null, lists each
remote and takes the lock — so it is the command to extend when a new dependency
on the environment appears.

**`app:validate --unattended` suppresses sends, not probes, and the distinction is
the whole flag.** Two of the checks are only worth their cost when somebody looks
at where the message landed: the eight-level log sweep and the run summary test
post. Neither is provable at this end — a webhook URL and `LOG_SLACK_LEVEL` are
both unverifiable from the sending side, and four records arriving at a threshold
of `error` proves both at once, which is why `checkDelivery()` prints the count to
compare against. Run from a deploy there is nobody to compare it, so the flag drops
those two and leaves everything else, including the `rclone` remote checks — a dead
remote is what an automated gate exists to surface, so an `--offline` flag would
throw away the reason for running it. Three rules if this is extended: never make it
the default (forgetting it costs recoverable noise, defaulting it costs every future
run its proof, silently); report skips rather than omitting rows; and do not bound
the sweep by channel instead — `driver !== 'slack'` looks like the test and is not,
since `papertrail` is `driver => monolog` and would send all eight off the box.

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

**The run summary is a notification, not a log record.** `App\Support\RunSummary`
is a container singleton — for the same reason `BackupLock` is, since the stages
`cron` runs are separate command objects — that collects what a run did:
`BaseCommand` records each backup written and each site that failed, `Cron`
records the stages. `App\Support\SlackSummary` renders it and posts it with
`hampel/slack-message`, which needs only a PSR-18 client (Guzzle is already a
`laravel-zero/framework` dependency, so it costs one package, not the 25 that
`illuminate/notifications` would). Sending happens *after* the lock is released
and can never fail the run.

**`SlackSummary` reads no config and resolves nothing.** Webhook, notify policy,
application string and hostname all arrive through its constructor, and
`AppServiceProvider` does the reading. Keep it that way: the same class has to
work under XenForo, where `config()`, `app()` and facades do not exist. If you
need a new setting in a message, add a constructor argument and bind it — do not
reach for `config()` inside the class. The `slack` log channel stays as the backstop; the
two are complementary, and `config/backup.php` says why.

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

**A mistyped command exits 1, which took an override.** `App\Kernel` narrows
`LaravelZero\Framework\Kernel::ensureDefaultCommand()` so only a bare invocation
or an options-only one is proxied to the default command; a first argument naming
nothing reaches Symfony and fails. Stock behaviour proxies it, so `wback
app:validte` prints the command list and exits 0 — the same silent success as the
scheduler above, in a tool whose exit code is the whole of what cron reads. It has
to be rebound in `bootstrap/app.php` over the binding `Application::configure()`
makes, or the class sits there doing nothing. `tests/Feature/UnknownCommandTest.php`
drives `Kernel::handle()` directly, because `$this->artisan()` calls the command and
never passes through the proxying.

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
- `composer test` runs the suite. There is deliberately no `format`, `lint` or
  `check` script, unlike the other tools here: those run Pint, and Pint would
  reformat this codebase for the reason above.
- **The repo is public, and the CHANGELOG is the part of it a stranger reads.**
  An entry is read by someone deciding whether to upgrade, so it says what
  changed and what that means for them, not which box the bug turned up on.
  Fleet hostnames belong in the commit message and in code comments, where the
  reader is a maintainer who needs to know the failure was real rather than
  hypothetical — `Validate.php` names `ap1` three times and each one earns its
  place. The 7.4.0 and 7.3.1 entries each narrate an incident and are left
  alone: the published release notes carry the same text word for word, and
  nothing on this box can edit those, so a file-only fix would just make the two
  records disagree.

## Releasing

**A tag push is the release.** `.github/workflows/ci.yml` runs the suite and
compiles the binary on every push and pull request; a tag additionally builds the
artefact, names the assets, writes `SHA256SUMS` and creates the GitHub release
with this file's CHANGELOG section as the notes. Nothing is compiled or uploaded
by hand any more, and the two things that used to go wrong cannot:

- **Tag first, then build.** `config/app.php` has `'version' => app('git.version')`,
  and `app:build` evaluates that file on the build machine and compiles the result
  in as a literal — so the binary reports whatever `git describe` said at build
  time. Building before tagging ships a binary announcing the previous release,
  which nothing downstream will contradict. A tag-triggered build cannot get the
  order wrong, and the release job asserts `--version` equals the tag before it
  publishes anything.
- **`composer build`, not `php wback app:build`.** `app:build` never runs Composer
  and `box.json` takes `vendor/` wholesale, so on a dev checkout it compiles the
  dev dependencies in too — Pint alone is 21 MB of a 29 MB binary, against 6 MB
  built properly. `composer build` installs `--no-dev`, builds, restores, and then
  greps the artefact for `laravel/pint` and fails if it finds it. That grep is the
  whole guard: nothing else notices, and 7.0.0 through 7.4.0 all shipped fat
  because nothing was looking. CI runs the same script, from a clean checkout.

So the release is:

1. CHANGELOG: move `Unreleased` to the new version, checked against
   `git log $(git describe --tags --abbrev=0)..HEAD` rather than against memory.
   The release job lifts that section verbatim into the release notes, matching
   on the `## [<tag>]` heading — get the heading wrong and it quietly falls back
   to generated notes rather than failing.
2. Update the version in the README's installation block.
3. Tag, and push the tag. Simon pushes; that push is the trigger.
4. Watch the run. It publishes `wback-<version>` and `SHA256SUMS`, and those two
   names are load-bearing — the README's `sha256sum --ignore-missing -c
   SHA256SUMS` only matches because the name inside the sums file is the
   downloaded filename, and pyinfra's `operations/wback.py` builds the same URL.
   Renaming either breaks provisioning and the documented install alike.
5. The smoke run — `wback app:validate` on each box that has it, **without
   `--unattended`**. CI cannot do this one: it needs the real binaries, databases
   and remotes. It is the only verification this tool gets, and it is the reason
   the command exists. Run it unflagged deliberately: since 7.5.0 the pyinfra
   deploy passes `--unattended` on `env: cloud` hosts, so **the deploy no longer
   proves the webhook works** — a suppressed send and a broken one look identical
   from the sending end, and an operator's unflagged run is now the only thing
   that checks it. Dev is deployed unflagged for the same reason, which keeps the
   send path exercised somewhere.
6. Bump `wback_version` and `wback_sha256` in `~/build` — **two files, not one**:
   `pyinfra/data/hosts.yml` for the fleet and `pyinfra/config.yml` for this dev
   box. They pin the checksum CI generated, so take it from the published
   `SHA256SUMS` rather than computing it locally; a local build is not guaranteed
   byte-identical to the runner's. That repo belongs to its own session — hand it
   over rather than editing it.
