<?php

return [

	/**
	 * Backup location - where to store the backups
	 */
	'backup_location' => '/var/www-backup',

	/**
	 * Path to nginx PID file so we can rotate logs
	 */
	'webserver_pid_path' => '/var/run/nginx.pid',

	/**
	 * Path to zip executable
	 *
	 * We choose not to use the Flysystem Zip adaptor (ZipArchive) because we are likely dealing with large numbers
	 * of files and potentially quite large archives, which are more efficiently handled by system zip files. Also,
	 * we get to use the exclude flag to manage exclusion lists easily.
	 */
	'zip_path' => '/usr/bin/zip',

	/**
	 * Filename to use for zip exclusion list
	 *
	 * wback will look for this file in the root of the website being backed up
	 */
	'zip_exclude_file' => '.backup-exclude',

	/**
	 * Path to mysqldump executable
	 *
	 */
	'mysqldump_path' => '/usr/bin/mysqldump',

	/**
	 * MySQL Username
	 */
	'mysql_username' => getenv('MYSQL_USERNAME'),

	/**
	 * MySQL Password
	 */
	'mysql_password' => getenv('MYSQL_PASSWORD'),

	/**
	 * MySQL Server
	 */
	'mysql_server' => getenv('MYSQL_SERVER'),

	/**
	 * Path to s3cmd executable
	 */
	's3cmd_path' => '/usr/bin/s3cmd',

	/**
	 * S3 Bucket
	 */
	's3_bucket' => getenv('S3_BUCKET'),

	/**
	 * S3 Access Key
	 */
	's3_access_key' => getenv('S3_ACCESS_KEY'),

	/**
	 * S3 Secret Key
	 */
	's3_secret_key' => getenv('S3_SECRET_KEY'),

	/**
	 * Days to keep backup files
	 *
	 * Files older than this will be removed
	 */
	'keeponly_days' => 7,

];