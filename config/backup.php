<?php

return [

    /**
     * Backup source TOML file path
     */
    'sites_path' => env('SITES_TOML_PATH', storage_path('wback.toml')),

	/**
	 * MySQL dump configuration
	 */
    'mysql' => [

	    /**
	     * Path to mysqldump binary
	     */
        'dump_path' => env('BACKUP_MYSQLDUMP_PATH', '/usr/bin/mysqldump'),

	    /**
	     * default charset for dump operations
	     * override for a specific database in the source configuration toml file
	     */
        'default_charset' => env('BACKUP_DEFAULT_CHARSET', 'utf8mb4'),

        /**
         * use --hex-blob option to store blobs as hex to avoid cross-platform export/import issues
         */
        'hexblob' => env('BACKUP_MYSQLDUMP_HEXBLOB', true),
    ],

    /**
     * Path to gzip binary for compressing database dumps
     */
	'gzip_path' => env('BACKUP_GZIP_PATH', '/bin/gzip'),

    /**
     * Path to zip binary for compressing files
     */
    'zip_path' => env('BACKUP_ZIP_PATH', '/usr/bin/zip'),

	/**
	 * Days to keep local backup files
	 *
	 * Files older than this will be removed from 'files' and 'database' directories
     * other directories will be handled by logrotate
	 */
	'keeponly_days' => env('BACKUP_KEEPONLY_DAYS', 7),

    /**
     * rclone remote for cloud storage ("remote:path_prefix")
     */
    'rclone_remote' => env('BACKUP_CLOUD_REMOTE'),

    /**
     * Schedule start time - scheduled commands will run based on offset specified for each command starting at this time in local timezone
     */
    'schedule_start' => env('SCHEDULE_START', 3),
];
