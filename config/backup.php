<?php

use Yosymfony\Toml\Toml;

return [

    /*
    |--------------------------------------------------------------------------
    | Config Path
    |--------------------------------------------------------------------------
    |
    | The path to find our sources config file.
    |
    | The config file should be in TOML format
    |
    | See: https://github.com/toml-lang/toml
    |
    | Default: storage/app/sources.toml
    |
    */

//    'sources_path' => env('BACKUP_SOURCES_PATH', storage_path('app/sources.toml')),

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

    'sources' => Toml::parseFile(env('BACKUP_SOURCES_PATH', storage_path('app/sources.toml'))),

    /*
    |--------------------------------------------------------------------------
    | Local Backup Storage
    |--------------------------------------------------------------------------
    |
    | Where to store the local backup files
    |
    | Default: /var/www-backup
    |
    */

//    'local_storage' => env('BACKUP_LOCAL_STORAGE_PATH', '/var/www-backup'),

    'mysql' => [
        'dump_path' => '/usr/bin/mysqldump',
        'default_charset' => 'utf8mb4',
    ],

	'gzip_path' => '/bin/gzip',

    'zip_path' => '/usr/bin/zip',

    'last_update_cache' => 60 * 60 * 24 * 7, // cache for a week

];
