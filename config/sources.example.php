<?php

return [

	/**
	 * format:
	 * '<name>' => [
	 * 		'url' => '<filename to use in destination paths>', // required
	 * 		'files' => '<path to website files>',
	 * 		'database' => '<database name>',
	 * 		'access' => '<path to web server access log>',
	 * 		'error' => '<path to web server error log>',
	 * ];
	 */

	'example' => [
		'url' => 'example.com',
		'files' => '/var/www/example.com',
		'database' => 'example',
		'access' => '/var/log/nginx/example.access.log',
		'error' => '/var/log/nginx/example.error.log',
		'sync' => [
			'wp-content/uploads',
		],
	],

];