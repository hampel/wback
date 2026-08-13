<?php

return [

    /**
     * Backup source TOML file path
     */
    'sites_path' => env('SITES_TOML_PATH', storage_path('wback.toml')),

    /**
     * Timezone for datestamped backup filenames and everything else this app reports
     *
     * It lives here rather than in config/app.php because app:build evaluates that file
     * on the build machine and compiles the result in as literals - an env() call in it
     * is frozen at build time and no .env beside the binary can change it. This file is
     * compiled as written, so the setting still works in a built binary.
     *
     * AppServiceProvider applies it over app.timezone as the application boots.
     */
    'timezone' => env('APP_TIMEZONE', 'Australia/Sydney'),

	/**
	 * MySQL dump configuration
	 */
    'mysql' => [

	    /**
	     * Path to mysqldump binary
	     */
        'dump_binary' => env('BACKUP_MYSQLDUMP_PATH', '/usr/bin/mysqldump'),

	    /**
	     * default charset for dump operations
	     * override for a specific database in the source configuration toml file
	     */
        'default_charset' => env('BACKUP_DEFAULT_CHARSET', 'utf8mb4'),

        /**
         * use --hex-blob option to store blobs as hex to avoid cross-platform export/import issues
         */
        'hexblob' => env('BACKUP_MYSQLDUMP_HEXBLOB', true),

        /**
         * use --single-transaction to dump from a consistent snapshot instead of locking
         * every table in the database for the duration of the dump
         *
         * only transactional tables (InnoDB) are covered by the snapshot
         * override for a specific database in the source configuration toml file
         */
        'single_transaction' => env('BACKUP_MYSQLDUMP_SINGLE_TRANSACTION', true),

        /**
         * additional options appended to every mysqldump command, inserted as written
         * override for a specific database in the source configuration toml file
         */
        'options' => env('BACKUP_MYSQLDUMP_OPTIONS', ''),

        /**
         * read each dump back and check mysqldump finished writing it
         *
         * costs a decompression pass - around 8% of the time the backup itself takes -
         * and needs the comments mysqldump writes by default, so turn it off for a
         * database dumped with --skip-comments or --compact
         *
         * override for a specific database in the source configuration toml file
         */
        'verify' => env('BACKUP_MYSQLDUMP_VERIFY', true),
    ],

    /**
     * Lock file keeping two backup runs off each other
     *
     * One lock covers every backup command, so a stage that overruns holds the next one
     * off rather than running over the top of it. Defaults to the root of the backup
     * destination, which is somewhere this tool can always write - set an absolute path
     * to put it somewhere else, /run/lock/wback.lock say.
     */
    'lock_file' => env('BACKUP_LOCK_FILE', ''),

    /**
     * Shell used to run commands containing a pipe
     *
     * A plain shell reports the exit status of the last command in a pipeline, which
     * would hide a failed mysqldump behind a successful gzip. Needs a shell supporting
     * "set -o pipefail" - leave empty to run pipelines under the system default shell
     */
    'shell' => env('BACKUP_SHELL', '/bin/bash'),

    /**
     * Path to gzip binary for compressing database dumps
     */
	'gzip_binary' => env('BACKUP_GZIP_PATH', '/bin/gzip'),

    /**
     * Path to zip binary for compressing files
     */
    'zip_binary' => env('BACKUP_ZIP_PATH', '/usr/bin/zip'),

    /**
     * rclone configuration
     */
    'rclone' => [

        /**
         * Path to rclone binary for transferring files
         */
        'binary' => env('BACKUP_RCLONE_PATH', '/usr/bin/rclone'),

        /**
         * rclone remote for cloud storage ("remote:path_prefix")
         */
        'cloud_remote' => env('BACKUP_CLOUD_REMOTE'),

        /**
         * rclone remote for sync storage ("remote:path_prefix")
         */
        'sync_remote' => env('BACKUP_SYNC_REMOTE'),

        /**
         * additional options for the sync command, inserted as written
         */
        'sync_options' => env('BACKUP_SYNC_OPTIONS', ''),

        /**
         * whether to sync a source directory that has become empty
         *
         * sync makes the remote match the source, so an empty source empties the remote
         * copy - which is what an unmounted filesystem or a mistyped path looks like
         */
        'sync_allow_empty' => env('BACKUP_SYNC_ALLOW_EMPTY', false),

        /**
         * directory on the sync remote to move replaced and deleted files into, dated,
         * instead of destroying them ("" to let sync delete them outright)
         */
        'sync_backup_dir' => env('BACKUP_SYNC_BACKUP_DIR', ''),
    ],

    /**
     * Days to keep local backup files
     *
     * Files older than this will be removed from 'files' and 'database' directories
     * other directories will be handled by logrotate
     */
    'keeponly_days' => env('BACKUP_KEEPONLY_DAYS', 7),

    /**
     * Days of backups kept whatever their age
     *
     * A floor under the retention period above, so that a run of failures lasting longer
     * than it does not expire the last backups that worked. Counted in days rather than
     * files, so several snapshots taken in one day are one day of cover.
     *
     * Set to 0 to expire strictly by age.
     */
    'keepleast_days' => env('BACKUP_KEEPLEAST_DAYS', 3),

];
