<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Backup source definitions
    |
    | Default: []
    |
    */

    'sources' => Yosymfony\Toml\Toml::parseFile(env('BACKUP_SOURCES_PATH', storage_path('app/sources.toml'))),

	/**
	 * MySQL dump configuration
	 */
    'mysql' => [

	    /**
	     * Path to mysqldump binary
	     */
        'dump_path' => '/usr/bin/mysqldump',

	    /**
	     * default charset for dump operations
	     * override for a specific database in the source configuration toml file
	     */
        'default_charset' => 'utf8mb4',
    ],

    /**
     * Path to gzip binary for compressing database dumps
     */
	'gzip_path' => '/bin/gzip',

    /**
     * Path to zip binary for compressing files
     */
    'zip_path' => '/usr/bin/zip',

	/**
	 * Days to keep local backup files
	 *
	 * Files older than this will be removed from 'files' and 'database' directories
     * other directories will be handled by logrotate
	 */
	'keeponly_days' => 7,

	/**
	 * Length of time (in seconds) to cache the last update data for sending backups to the cloud
	 *
	 * Default: 60 * 60 * 24 * 7 = 604800 = 1 week
	 */
    'last_update_cache' => 60 * 60 * 24 * 7, // cache for a week

];
