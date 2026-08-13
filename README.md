# wback

Website backup for a server hosting many sites. Each site is an entry in a TOML
inventory; `wback` dumps its database, archives its files, ships the results to
cloud storage and expires the old ones.

It is a thin, well-behaved wrapper: every backup is performed by `mysqldump`,
`gzip`, `zip` or `rclone`, and every command can be run against a single site or
all of them, for real or as a dry run.

## Requirements

- PHP 8.2 or later, with the CLI SAPI
- `mysqldump` and `gzip` for database backups
- `zip` for file backups
- [rclone](https://rclone.org/) for cloud storage, with a configured remote

## Installation

```bash
composer install
```

Run it from the source tree with `php wback <command>`, or build a single-file
executable:

```bash
php wback app:build wback
```

The result is `builds/wback`, which is the artefact to deploy to a server.

## Configuration

Two files: `.env` for the environment, and a TOML file listing the sites.

### Where the configuration is read from

This differs between a source checkout and a built binary, and it is the most
common thing to get wrong when deploying:

| | source checkout | built PHAR |
|---|---|---|
| `.env` | the project root | **the directory containing the binary** |
| storage path (default sites file, backup destination and log) | `./storage` | the **current working directory**, or `LARAVEL_STORAGE_PATH` |

Because the storage path follows the working directory, set `SITES_TOML_PATH`,
`BACKUP_DEST_PATH` and `LOG_STORAGE_PATH` to absolute paths on a server rather
than relying on the defaults.

`php wback app:config` prints every resolved path, binary and remote — run it
first when something is not where you expect.

### Environment

Copy `.env.example` and set what you need; every setting has a default.

| variable | default | purpose |
|---|---|---|
| `SITES_TOML_PATH` | `<storage>/wback.toml` | the site inventory |
| `FILES_ROOT` | `/srv/www` | where site files are looked for |
| `BACKUP_DEST_PATH` | `<storage>/backup` | where backups are written |
| `BACKUP_KEEPONLY_DAYS` | `7` | how long `clean` keeps local backups |
| `BACKUP_MYSQLDUMP_PATH` | `/usr/bin/mysqldump` | |
| `BACKUP_DEFAULT_CHARSET` | `utf8mb4` | dump charset; blank omits the option |
| `BACKUP_MYSQLDUMP_HEXBLOB` | `true` | dump blobs as hex, for portable restores |
| `BACKUP_GZIP_PATH` | `/bin/gzip` | |
| `BACKUP_ZIP_PATH` | `/usr/bin/zip` | |
| `BACKUP_RCLONE_PATH` | `/usr/bin/rclone` | |
| `BACKUP_CLOUD_REMOTE` | — | `remote:prefix` for `cloud`; required by that command |
| `BACKUP_SYNC_REMOTE` | — | `remote:prefix` for `sync`; required by that command |
| `SCHEDULE_START` | `3` | hour the nightly run begins |
| `LARAVEL_STORAGE_PATH` | working directory | storage path, PHAR only |
| `LOG_CHANNEL` | `stack` | `single`, `daily`, `slack`, `syslog`, `stack`, … |
| `LOG_STACK` | `null` | channels in the stack, comma separated |
| `LOG_STORAGE_PATH` | `<storage>/wback.log` | |
| `LOG_LEVEL` | `debug` | |
| `LOG_SLACK_WEBHOOK_URL` | — | |
| `LOG_SLACK_LEVEL` | `critical` | |

`wback` passes no credentials to `mysqldump`, so the invoking user needs its own
working MySQL client configuration — socket authentication, or a `~/.my.cnf`
holding the username and password.

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
| `hostname` | local socket | database host, for a remote server |
| `files` | `<FILES_ROOT>/<domain>` | set to `''` to skip files entirely |
| `exclude` | none | patterns passed to `zip --exclude`; wildcards are escaped for you |
| `sync` | none | paths, relative to the file source, mirrored live by `sync` |

Omitting a key and setting it to `''` mean different things: omit `database` and
the short name is used; set it to `''` and the site has no database. The same
applies to `files`, for a database-only site such as the Zabbix example above.

`php wback app:sites` prints the inventory as parsed, which is the quickest way
to check a TOML edit did what you meant.

## Commands

Every backup command takes an optional site name, and the same three options:

| option | effect |
|---|---|
| `-a`, `--all` | process every configured site; **overrides** a site named on the command line |
| `-d`, `--dry-run` | report what would happen without changing anything |
| `-v`, `-vv`, `-vvv` | more output, up to the command lines being executed |

With neither a site nor `--all`, the command prints its usage and the configured
sites, and exits non-zero.

### `database` — dump databases

```
mysqldump --opt --default-character-set=<charset> --hex-blob <database> | gzip -c -f > <destination>
```

### `files` — archive site files

Runs from the site's file source, so paths in the archive are relative to it:

```
zip -9 --recurse-paths --symlinks <destination> . --exclude <patterns>
```

### `cloud` — copy backups off the machine

```
rclone --progress copy <backup>/<domain> <BACKUP_CLOUD_REMOTE>/<domain>
```

A copy, not a mirror: nothing on the remote is ever deleted, so remote retention
is the storage provider's job (a bucket lifecycle rule, for instance).

### `sync` — mirror live directories

For each `sync` path of the site:

```
rclone --progress sync <source>/<path> <BACKUP_SYNC_REMOTE>/<domain>/sync/<path>
```

This one *is* a mirror — files deleted locally are deleted on the remote. It is
meant for large directories worth keeping current but not worth zipping nightly.

### `clean` — expire local backups

Deletes anything older than `BACKUP_KEEPONLY_DAYS` from each site's `files` and
`database` directories. Only those two directories are touched, and only
locally.

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
| `app:test` | writes a message at each log level, to prove out logging |

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

Datestamps and schedule times use the application timezone, which is fixed at
`Australia/Sydney` in `config/app.php`.

## Scheduling

The commands are scheduled an hour apart, starting at `SCHEDULE_START`, in the
order they depend on each other: `database`, `files`, `cloud`, `sync`, `clean`.
With the default start of 3, the nightly run is 03:00 to 07:00. The hours wrap
around midnight, so a late start time is fine.

Each scheduled command runs with `--quiet --all`, so only errors are printed and
cron mails you nothing on a clean night.

Driving it needs one cron entry, per Laravel's scheduler:

```cron
# /etc/cron.d/wback  (no dot in the filename, or run-parts ignores it)
* * * * * backup cd /srv/backup && /usr/local/bin/wback schedule:run
```

The `cd` matters: it fixes the storage path, and so where the backups land.

Use `php wback schedule:list` to see the resolved times.

## Logging

Everything reported on the console is also logged through Monolog, with
structured context. Console verbosity and log level are independent: a `--quiet`
scheduled run still logs at full detail.

**Out of the box nothing is written.** The default channel is `stack`, and an
unset `LOG_STACK` makes that stack the `null` channel, which discards
everything. Set `LOG_CHANNEL=single` or `daily` for a file, or keep the stack and
set `LOG_STACK=single,slack` to write a file and raise critical failures in
Slack. `php wback app:test` writes one message at every level so you can confirm
where they land.

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
