# wback

Website backup for a server hosting many sites. Each site is an entry in a TOML
inventory; `wback` dumps its database, archives its files, ships the results to
cloud storage and expires the old backup files.

It is a thin, well-behaved wrapper: every backup is performed by `mysqldump`,
`gzip`, `zip` or `rclone`, and every command can be run against a single site or
all of them, for real or as a dry run.

## Requirements

- PHP 8.3 or later, with the CLI SAPI
- `mysqldump` or `mariadb-dump`, and `gzip`, for database backups
- `zip` for file backups
- [rclone](https://rclone.org/) for cloud storage, with a configured remote

## Assumptions

`wback` is deliberately thin, which means it inherits a set of assumptions about
the machine it runs on. Most of them can be worked around with an explicit
setting; where that is the case it is noted.

**The tools are already configured.**

- `mysqldump` is passed no username or password, so the user running `wback`
  needs credentials of its own — socket authentication, or a `~/.my.cnf`. The
  same applies under cron, where the user is often not the one you tested as.
- A remote database is reached over TCP with `hostname` and, if it is not on the
  default port, `port`. There is no socket path setting.
- `rclone` reads its own configuration, and `wback` passes no `--config`, so the
  remotes must be configured for the user running the backup.
- Dumps come from an InnoDB snapshot by default, so anything important held in
  MyISAM or MEMORY tables needs `single_transaction = false` for that site — see
  [Large databases](#large-databases).

**Names and layout follow a convention.**

- A site's files live at `<FILES_ROOT>/<domain>` — named for the domain,
  `/srv/www/example.com` rather than `/srv/www/example`. Sites that break the
  convention need an explicit `files` path.
- `domain` names the destination directory, so two sites sharing a domain share
  a directory. The short name distinguishes the files inside it.
- Values taken from the inventory — database names, paths, exclude patterns,
  remotes — are quoted before they reach the shell, so spaces and punctuation in
  them are safe. The configured binary paths are the exception: they are
  inserted as written, which is what lets `BACKUP_GZIP_PATH` be something like
  `nice -n 19 /usr/bin/pigz`, and equally means they must not be built out of
  anything but your own configuration.
- Everything is local: files are read from a local path and backups are written
  to a local path. Only the database may be remote.

**The backups are full and daily.**

- Every run archives everything. There are no incremental or differential
  backups, so local disk needs roughly `BACKUP_KEEPONLY_DAYS` times the full size
  of every site.
- Filenames are datestamped to the day and retention is counted in whole days,
  so the intended cadence is daily. More frequent runs work — they just get a
  counter suffix, and expire together.
- `clean` goes by file modification time, and only inside each site's `files` and
  `database` directories. Whatever else lives under a site's backup root is left
  alone, and stays the responsibility of whatever put it there: logs archived
  into it by logrotate, for instance, are expired by logrotate's own retention,
  not by `clean`. `cloud` is the opposite — it copies the site's backup root
  wholesale, so anything dropped there does get shipped to the remote.
- Nothing is encrypted: the dump and the archive land on the remote as written.
  Use an rclone crypt remote if that matters.
- Database dumps are checked for completeness, but nothing is test-restored, and
  file archives are not checked at all. `wback` has no restore command either:
  restoring is `gunzip | mysql` and `unzip`, by hand.

**One run at a time.**

- The stages run one after another, and only one backup runs at a time, so
  `cloud` cannot upload an archive that `files` is still writing.
- That makes the lock a single point of contention by design: a site large
  enough to take all night stops tonight's run from starting at all.
- Backup files are created with mode 0660, so access is controlled by the
  backup user's group.

## Installation

### From a release

Each release publishes a single-file executable on the
[releases page](https://github.com/hampel/wback/releases). It carries everything
but PHP itself, so a server needs nothing else installed — no composer, no
vendor directory:

```bash
curl -L -o wback https://github.com/hampel/wback/releases/download/7.1.0/wback-7.1.0
chmod +x wback
sudo mv wback /usr/local/bin/wback
```

Each release also has a `SHA256SUMS` file, so the download can be checked before
you trust it with your backups:

```bash
sha256sum -c SHA256SUMS
```

Which release you want depends on the PHP on the server: **7.1.0 and later need
PHP 8.3**, and **7.0.0 runs on PHP 8.2**.

Then give it a `.env` — beside the binary, at `/etc/wback/.env`, or wherever
`WBACK_ENV` points. See [Configuration](#configuration), and note that the
storage path follows the working directory rather than the binary.
`wback app:validate` will tell you whether the server can do what the
configuration says.

### From source

```bash
composer install
```

Run it from the source tree with `php wback <command>`, or build the same
single-file executable yourself:

```bash
php wback app:build wback
```

The result is `builds/wback`. Building needs `phar.readonly=Off` in the CLI
php.ini, or `php -d phar.readonly=0 wback app:build wback`.

## Configuration

Two files: `.env` for the environment, and a TOML file listing the sites.

### Where the configuration is read from

This differs between a source checkout and a built binary, and it is the most
common thing to get wrong when deploying:

| | source checkout | built binary |
|---|---|---|
| `.env` | the project root | beside the binary, `WBACK_ENV`, or `/etc/wback/.env` |
| storage path (default sites file, backup destination and log) | `./storage` | the **current working directory**, or `LARAVEL_STORAGE_PATH` |

Because the storage path follows the working directory, set `SITES_TOML_PATH`,
`BACKUP_DEST_PATH` and `LOG_STORAGE_PATH` to absolute paths on a server rather
than relying on the defaults.

The environment file is looked for in this order, first one that exists winning:

1. **beside the binary** — `.env` next to the executable
2. **`WBACK_ENV`**, naming the file itself, wherever it is
3. the project's own `.env`, running from a source checkout
4. **`/etc/wback/.env`**

Which means the binary can go somewhere on the path and its configuration can sit
with the rest of the system's, with nothing to pass at all:

```bash
sudo install -m 755 wback-7.1.0 /usr/local/bin/wback
sudo install -d /etc/wback
sudo install -m 640 .env /etc/wback/.env
wback app:config          # reports which file it read
```

Note the first entry: a `.env` beside the binary is loaded last by the framework
and so overrides the others. Keep one there only if you mean it to win.

`php wback app:config` prints every resolved path, binary and remote — run it
first when something is not where you expect.

### Environment

Copy `.env.example` and set what you need; every setting has a default.

| variable | default | purpose |
|---|---|---|
| `SITES_TOML_PATH` | `<storage>/wback.toml` | the site inventory |
| `APP_TIMEZONE` | `UTC` | timezone for datestamps and reporting |
| `FILES_ROOT` | `/srv/www` | where site files are looked for |
| `BACKUP_DEST_PATH` | `<storage>/backup` | where backups are written |
| `BACKUP_KEEPONLY_DAYS` | `7` | how long `clean` keeps local backups |
| `BACKUP_KEEPLEAST_DAYS` | `3` | days of backups `clean` keeps whatever their age |
| `BACKUP_MYSQLDUMP_PATH` | `/usr/bin/mysqldump` | |
| `BACKUP_DEFAULT_CHARSET` | `utf8mb4` | dump charset; blank omits the option |
| `BACKUP_MYSQLDUMP_HEXBLOB` | `true` | dump blobs as hex, for portable restores |
| `BACKUP_MYSQLDUMP_SINGLE_TRANSACTION` | `true` | snapshot instead of locking every table |
| `BACKUP_MYSQLDUMP_OPTIONS` | — | extra mysqldump options, inserted as written |
| `BACKUP_MYSQLDUMP_VERIFY` | `true` | read each dump back and check it is complete |
| `BACKUP_SHELL` | `/bin/bash` | shell for the dump pipeline; needs `pipefail` |
| `BACKUP_GZIP_PATH` | `/bin/gzip` | see [Large databases](#large-databases) |
| `BACKUP_ZIP_PATH` | `/usr/bin/zip` | |
| `BACKUP_RCLONE_PATH` | `/usr/bin/rclone` | |
| `BACKUP_CLOUD_REMOTE` | — | `remote:prefix` for `cloud`; required by that command |
| `BACKUP_SYNC_REMOTE` | — | `remote:prefix` for `sync`; required by that command |
| `BACKUP_SYNC_ALLOW_EMPTY` | `false` | allow a sync from a source that has gone empty |
| `BACKUP_SYNC_BACKUP_DIR` | — | keep replaced files under this directory instead of deleting |
| `BACKUP_CLOUD_OPTIONS` | — | extra rclone options for `cloud`, inserted as written |
| `BACKUP_SYNC_OPTIONS` | — | extra rclone options for `sync`, inserted as written |
| `BACKUP_LOCK_FILE` | `<destination>/.wback.lock` | lock keeping two runs off each other |
| `LARAVEL_STORAGE_PATH` | working directory | storage path, built binary only |
| `LOG_CHANNEL` | `stack` | `single`, `daily`, `slack`, `syslog`, `stack`, … |
| `LOG_STACK` | `null` | channels in the stack, comma separated |
| `LOG_STORAGE_PATH` | `<storage>/wback.log` | |
| `LOG_LEVEL` | `debug` | |
| `LOG_SLACK_WEBHOOK_URL` | — | |
| `LOG_SLACK_LEVEL` | `critical` | |

### Sites

Each table in the TOML file is one site, named by a short name. `domain` is the
only required key, and is used as the directory name for that site's backups.

```toml
[example]
domain = 'example.com'

[shop]
domain = 'shop.example.com'
database = 'shop_prod'
charset = 'latin1'
hostname = 'db.internal'
port = 3307
files = '/srv/apps/shop/public'
exclude = [
    'data/tmp/*',
    'internal_data/cache/*',
]
sync = [
    'data/documents',
]

[zabbix]
domain = 'zabbix.example.com'
files = ''
```

| key | default | notes |
|---|---|---|
| `domain` | *required* | names the backup directory for the site |
| `database` | the short name | set to `''` to skip the database entirely |
| `charset` | `BACKUP_DEFAULT_CHARSET` | passed as `--default-character-set` |
| `hostname` | local socket | database host, passed as `-h` |
| `port` | mysqldump's default | database port, passed as `-P`; only meaningful with `hostname` |
| `verify` | `BACKUP_MYSQLDUMP_VERIFY` | read the dump back and check it is complete |
| `single_transaction` | `BACKUP_MYSQLDUMP_SINGLE_TRANSACTION` | snapshot rather than lock; see below |
| `options` | `BACKUP_MYSQLDUMP_OPTIONS` | extra mysqldump options, replacing the global ones |
| `files` | `<FILES_ROOT>/<domain>` | set to `''` to skip files entirely |
| `exclude` | none | patterns passed to `zip --exclude`; wildcards are escaped for you |
| `sync` | none | paths, relative to the file source, mirrored live by `sync` |

Omitting a key and setting it to `''` mean different things: omit `database` and
the short name is used; set it to `''` and the site has no database. The same
applies to `files`, for a database-only site such as the Zabbix example above.

`php wback app:sites` prints the inventory as parsed, which is the quickest way
to check a TOML edit did what you meant. It lists every key a site sets,
including the ones set to nothing — `files: (none)` is a site with files
deliberately turned off, where a site that simply leaves `files` out does not
list it at all.

## Commands

`cron` runs the lot, in order, and is what a cron entry should call — see
[Running it from cron](#running-it-from-cron). The rest are the individual
stages, and every one of them takes an optional site name and the same three
options:

| option | effect |
|---|---|
| `-a`, `--all` | process every configured site; **overrides** a site named on the command line |
| `-d`, `--dry-run` | report what would happen without changing anything |
| `-v`, `-vv`, `-vvv` | more output, up to the command lines being executed |

With neither a site nor `--all`, the command prints its usage and the configured
sites, and exits non-zero.

### `database` — dump databases

```
bash -o pipefail -c 'mysqldump --opt --default-character-set=<charset> --hex-blob \
    --single-transaction <database> | gzip -c -f > <destination>'
```

Each dump is then read back and checked for the marker mysqldump writes when it
finishes. `pipefail` catches a dump that *reports* failure; this catches the one
that does not. A mysqldump stopped partway leaves a file that is a perfectly
valid gzip — `gzip -t` passes it — holding a fraction of a database, and nothing
else about it says so:

```
Backed up demo.20260813.sql.gz - 108.00 kB
Dump [demo.example.com/database/demo.20260813.sql.gz] is incomplete - mysqldump
did not finish writing it. The file has been kept so you can look at it
```

It costs one decompression pass, about 8% of the time the backup itself takes (6
seconds against 72 on a 2GB dump), and the file is kept rather than deleted so
you can see what you got. Turn it off with `BACKUP_MYSQLDUMP_VERIFY=false`, or
per site with `verify = false` — which you must do for a database dumped with
`--skip-comments` or `--compact`, since those remove the marker.

The `pipefail` wrapper is not decoration. A shell reports the exit status of the
*last* command in a pipeline, so without it a mysqldump that dies partway — wrong
credentials, a dropped connection, a full disk — is masked by the gzip that
compresses the partial output and exits 0. The result is a valid gzip file
containing half a database, recorded as a successful backup. Set `BACKUP_SHELL`
empty to go back to a plain shell, knowing that is the trade.

#### Large databases

Two settings matter once a database is big enough that the dump takes minutes.

**`--single-transaction`** (on by default) is the difference between a backup
your users notice and one they don't. Without it, `--opt` implies
`--lock-tables`, which read-locks *every table in the database* for the whole
dump — on a 280-table forum that is 281 tables locked at once, for as long as the
dump and the compression take. With it, the dump comes from a consistent InnoDB
snapshot and writes carry on unaffected.

The catch is that only transactional tables are in that snapshot. MyISAM and
MEMORY tables are dumped as they are read, so they can be inconsistent with the
rest. Whether that matters depends on what they hold — a rebuildable search index
or a session table, no; anything else, set `single_transaction = false` for that
site or convert the tables to InnoDB. To find them:

```sql
SELECT table_name, engine FROM information_schema.tables
 WHERE table_schema = 'yourdb' AND engine <> 'InnoDB';
```

**Compression is usually the bottleneck**, not MySQL. On a 2GB dump, the dump
alone took 32s, and piping it into `gzip` at its default level took 88s — nearly
three times as long, and with `--lock-tables` every one of those extra seconds is
lock held. `BACKUP_GZIP_PATH` is inserted as written, so:

```dotenv
BACKUP_GZIP_PATH=/usr/bin/pigz        # same ratio, spread across every core
BACKUP_GZIP_PATH="/bin/gzip -1"       # ~2x faster, ~20% more storage
```

### `files` — archive site files

Runs from the site's file source, so paths in the archive are relative to it:

```
zip -9 --recurse-paths --symlinks <destination> . --exclude <patterns>
```

### `cloud` — copy backups off the machine

```
rclone --stats-one-line --stats 1m copy <backup>/<domain> <BACKUP_CLOUD_REMOTE>/<domain>
```

A copy, not a mirror: nothing on the remote is ever deleted, so remote retention
is the storage provider's job (a bucket lifecycle rule, for instance).

**Reporting depends on where the output is going.** Run from a terminal, both
rclone commands get `--progress` and draw the usual live display. Anywhere else —
cron, a log file, a pipe — they get `--stats-one-line --stats 1m` instead,
because a progress display off a terminal writes the whole thing again every half
second: 47 lines for a six second transfer, and proportionally more for a real
one. `BACKUP_CLOUD_OPTIONS` and `BACKUP_SYNC_OPTIONS` are inserted after these,
so putting `--progress` in one of them forces the display back on.

### `sync` — mirror live directories

For each `sync` path of the site:

```
rclone --stats-one-line --stats 1m sync <source>/<path> <BACKUP_SYNC_REMOTE>/<domain>/sync/<path>
```

This one *is* a mirror — files deleted locally are deleted on the remote. It is
meant for large directories worth keeping current but not worth zipping nightly.

Which makes it the one command that can destroy a backup rather than fail to
make one, so it has two guards:

**An empty source is refused.** A source directory that exists but holds nothing
is what an unmounted filesystem and a mistyped path both look like, and syncing
it would faithfully empty the remote copy to match. The site fails with a message
saying so, and the remote is left alone. Set `BACKUP_SYNC_ALLOW_EMPTY=true` for
the case where the directory really is meant to be empty.

**`BACKUP_SYNC_BACKUP_DIR` keeps what sync would destroy.** Set it to a directory
name and everything sync replaces or deletes is moved to
`<remote>/<domain>/<name>/<date>/<path>` instead, making a sync that went wrong
cost storage rather than data. It expires nothing, so pair it with a lifecycle
rule on the bucket.

A note on `--max-delete`, the obvious third option: it is a damage limiter, not a
guard. rclone deletes up to the threshold and *then* errors, so both a genuine
fault and a false positive — a directory rename counts every file in it as a
deletion — leave the remote half mirrored. It is available through
`BACKUP_SYNC_OPTIONS` if you want it, but the two guards above are what wback
leans on.

### `clean` — expire local backups

Deletes anything older than `BACKUP_KEEPONLY_DAYS` from each site's `files` and
`database` directories. Only those two directories are touched, and only
locally.

Underneath that sits a floor: the most recent `BACKUP_KEEPLEAST_DAYS` days of
backups are kept whatever their age. Age on its own eventually leaves nothing —
a fault that stops backups for longer than the retention period expires the last
good ones along with the rest, and you find out when you need them. The floor
only ever *prevents* a deletion, so it can never remove something age would have
kept.

It counts days rather than files, so several snapshots taken in one afternoon are
one day of cover rather than several — worth knowing if you take extra snapshots
while working on a site. It applies per site and per backup type, so a site whose
files keep backing up while its database quietly fails still holds on to the last
database dumps that worked. Set it to 0 to expire strictly by age.

### Dry runs

`--dry-run` means "change nothing", but it reaches that differently per command:
`database`, `files` and `clean` log the work and skip it, while `cloud` and
`sync` really do run rclone, with rclone's own `--dry-run` so it reports the
transfers it would make.

### Inspecting the setup

| command | purpose |
|---|---|
| `app:config` | every resolved path, binary, remote and log setting |
| `app:sites [site]` | the site inventory, as parsed |
| `app:validate` | runs the binaries, connects to the databases, lists the remotes |

`app:validate` is the one to run after provisioning a server, and the first one
to run when backups have gone quiet. It exercises the real thing rather than
describing it:

- every configured binary is **run**, which also proves a setting carrying
  options of its own still resolves to something executable
- every database is **dumped** — schema only, to `/dev/null` — through the same
  binary, credentials and user the backup will use
- every remote is **listed**, distinguishing a remote it cannot reach at all
  (an error) from a path that does not exist yet (a warning, normal before the
  first transfer)
- the sites file parses, every site has a domain, and each file source and sync
  path is there
- the backup destination exists, is writable and has room, and the lock can be
  taken and released
- a message is written at every log level, so you can confirm where they land

It exits non-zero if anything failed, so it works as a post-deploy check.

## Where backups end up

```
<BACKUP_DEST_PATH>/
└── example.com/
    ├── database/
    │   └── example.20260813.sql.gz
    └── files/
        └── example.20260813.zip
```

Files are named for the site's short name and the date. A second run on the same
day does not overwrite the first — it appends a counter, `example.20260813-2.zip`.

Datestamps use `APP_TIMEZONE`, which defaults to `UTC`. Set it to the timezone
the backup window is expressed in, or dates will move when your clocks do.

That setting lives in `config/backup.php`, not `config/app.php`, and the reason is
worth knowing if you ever add another one: `app:build` **evaluates
`config/app.php` on the build machine and compiles the result in as literals**,
so an `env()` call in that file is resolved at build time and frozen. No `.env`
beside the built binary can change it — which is why the timezone appeared
unconfigurable for years. Every other config file is compiled as written, so put
anything that needs to stay configurable in one of those.

## Running it from cron

One entry, and `cron` runs every backup in turn:

```cron
# /etc/cron.d/wback  (no dot in the filename, or run-parts ignores it)
0 3 * * * backup cd /srv/backup && /usr/local/bin/wback cron --quiet
```

The `cd` matters: it fixes the storage path, and so where the backups land.
`--quiet` prints errors only, so cron mails you nothing on a clean night.

The stages run in the order they depend on each other — `database`, `files`,
`cloud`, `sync`, `clean` — each starting when the one before it has actually
finished. A stage that fails does not stop the ones after it, and the command
exits non-zero if any of them failed.

Each stage can be turned off: `--no-database`, `--no-files`, `--no-cloud`,
`--no-sync`, `--no-clean`. That is how to back up locally on a machine whose
cloud credentials are not wired up yet, or on one that is never going to have
any:

```bash
wback cron --quiet --no-cloud --no-sync
```

A stage that *is* meant to run and cannot still fails the run — a missing
`BACKUP_CLOUD_REMOTE` is an error, not a hint to skip the upload, because a
deployment that lost its configuration looks exactly like one that never had any
and only you know which it is. Skipped stages say so in the log.

You can still drive the commands individually if you want them spread across the
night, but then the spacing is a guess about how long each takes, and two of them
running at once is prevented by the lock rather than by the timing:

```cron
0 3 * * * backup cd /srv/backup && /usr/local/bin/wback database --quiet --all
0 4 * * * backup cd /srv/backup && /usr/local/bin/wback files --quiet --all
0 5 * * * backup cd /srv/backup && /usr/local/bin/wback cloud --quiet --all
0 6 * * * backup cd /srv/backup && /usr/local/bin/wback sync --quiet --all
0 7 * * * backup cd /srv/backup && /usr/local/bin/wback clean --quiet --all
```

Leave enough room between them for the slowest site, and remember that a stage
which overruns into the next one's hour does not run alongside it — the later
command finds the lock held, reports it, and waits for tomorrow.

### Not Laravel Zero's scheduler

wback does not use it, and the `schedule:*` commands are removed from the
application so they cannot be run by mistake. It is broken here twice over:

- A due event resolves `Illuminate\Log\Context\Repository`, which uses a trait
  from `illuminate/queue` — a package console applications do not install. The
  command dies with `Trait "Illuminate\Queue\SerializesModels" not found`.
- In a compiled binary, the working directory handed to Symfony Process is a
  `phar://` path, which Process rejects, and `ARTISAN_BINARY` is a relative
  string that does not match the built executable's name.

What makes it dangerous rather than merely broken is the reporting:
`ScheduleRunCommand` catches the failure and returns `$event->exitCode == 0`,
where `exitCode` is still `null` — and `null == 0` is true. It prints DONE for
every task and exits 0 having run nothing at all. For a backup tool that is the
worst available outcome: everything reports success and there are no backups.

(Aliasing a command is separately unwise. `setAliases()` registers the command
once per alias, so a scheduled backup would run twice.)

### One run at a time

Every backup command takes a single exclusive lock for as long as it runs, so
the stages can never overlap: a `files` backup that runs past its hour holds
`cloud` off rather than having it upload an archive that is still being written.
A command that finds the lock held reports what holds it and exits non-zero:

```
Another backup is still running [pid 1116187, files, started 2026-08-13 15:25:11] - skipping this run
```

That is deliberately an error rather than a quiet skip — a stage that keeps
colliding with the one before it is worth hearing about. Dry runs ignore the lock
entirely, since they change nothing.

The lock file lives at the root of the backup destination, the one directory this
tool can always write to, and is called `.wback.lock`. It sits outside every
site's directory, so `cloud` never uploads it and `clean` never expires it. Point
`BACKUP_LOCK_FILE` at an absolute path — `/run/lock/wback.lock`, say — to put it
elsewhere; do that if your backup destination is a network filesystem, where file
locking is best avoided.

## Logging

Everything reported on the console is also logged through Monolog, with
structured context. Console verbosity and log level are independent: a `--quiet`
scheduled run still logs at full detail.

**Out of the box nothing is written.** The default channel is `stack`, and an
unset `LOG_STACK` makes that stack the `null` channel, which discards
everything. Set `LOG_CHANNEL=single` or `daily` for a file, or keep the stack and
set `LOG_STACK=single,slack` to write a file and raise critical failures in
Slack. `php wback app:validate` writes one message at every level so you can
confirm where they land.

Log files are not rotated by `clean`; use logrotate.

## Development

```bash
php wback test                        # the full suite (Pest)
vendor/bin/pest --filter='dry run'    # one test
```

The suite fakes the process runner and the filesystem, so no external binary is
executed and nothing outside a temporary directory is written. It asserts on the
shell command each backup command assembles, that string being what the
application actually produces.

## Built with

[Laravel Zero](https://laravel-zero.com/), a console micro-framework built on
Laravel's components.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).
