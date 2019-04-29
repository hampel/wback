<?php

use App\Sync\S3Cmd;

return [

	/**
	 * Default sync service
	 */
	'default' => env('SYNC_SERVICE', 's3'),

	/**
	 * Cloud sync services
	 */
    'services' => [
        's3' => [
            'builder' => S3Cmd::class,
            'awscli' => env('SYNC_AWS_CLI'),
            'bucket' => env('SYNC_S3_BUCKET'),
            'storage_class' => env('SYNC_S3_STORAGE_CLASS', 'ONEZONE_IA'),
        ],
    ],
];
