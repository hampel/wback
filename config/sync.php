<?php

use App\Sync\S3Cmd;

return [

	/**
	 * Default sync service
	 */
	'default' => env('SYNC_SERVICE', 's3'),

	/*
	 * Path prefix to add to all destination files
	 */
	'prefix' => env('SYNC_PATH_PREFIX'),

	/**
	 * Cloud sync services
	 */
    'services' => [
        's3' => [
            'builder' => S3Cmd::class,
            'awscli' => env('SYNC_AWS_CLI', '~/.local/bin/aws'),
            'bucket' => env('SYNC_S3_BUCKET'),
            'storage_class' => env('SYNC_S3_STORAGE_CLASS', 'STANDARD_IA'),
        ],
    ],
];
