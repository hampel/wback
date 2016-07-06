<?php

return [

	/**
	 * format:
	 * '<name>' => [
	 * 		'url' => '<filename to use in destination paths>', // required
	 * 		'files' => '<path to website files>',
	 * 		'database' => '<database name>',
	 * 		'hostname' => '<database hostname>', // optional - will use default hostname from .env if not set
	 * 		'logs' => '<path to compressed log files>' // either set to full path where logrotate stores compress logs
	 * 												   // or set to wback logs storage eg /var/www-backup/example.com/logs
	 * 		'access' => '<path to web server access log>', // only if you want wback to rotate log files for you (don't set if you use logrotate)
	 * 		'error' => '<path to web server error log>', // only if you want wback to rotate log files for you (don't set if you use logrotate)
	 * 		'sync' => [ // optional
	 * 			'<relative path for additional files to sync>' // paths relative to 'files' above - no trailing slashes!
	 * 		],
	 * ],
	 */

	'example' => [
		'url' => 'example.com',
		'files' => '/var/www/example.com',
		'database' => 'example',
		'logs' => '/var/log/nginx/example.com/*.gz',
		//'logs' => '/var/www-backup/example.com/logs',
		//'access' => '/var/log/nginx/example.access.log',
		//'error' => '/var/log/nginx/example.error.log',
		'sync' => [
			'wp-content/uploads',
		],
	],

];