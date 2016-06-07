#! /usr/bin/env php

<?php

use WBack\ListCommand;
use WBack\FilesCommand;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\ConsoleOutput;

require 'vendor/autoload.php';

$config = loadConfig();
loadEnv();

$app = new Application('wback Website Backup', '1.0');

$list = new ListCommand(null, $config);
$app->add($list);
$app->add(new FilesCommand(null, $config));

$app->setDefaultCommand($list->getName());

$app->run();

/**
 * -------------------------------------------------------------------------------------------------------------------
 *
 * Functions
 */
function loadConfig()
{
	$config_path = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'config');

	foreach (Finder::create()->files()->name('*.php')->in($config_path) as $file) {
		$config[basename($file->getRealPath(), '.php')] = require $file->getRealPath();
	}

	if (!array_key_exists('app', $config))
	{
		$out = new ConsoleOutput();
		$out->writeln('<error>missing app config</error>');
		exit;
	}

	if (!array_key_exists('sources', $config))
	{
		$out = new ConsoleOutput();
		$out->writeln('<error>missing source config</error>');
		exit;
	}

	return $config;
}

function loadEnv()
{
	try
	{
		$dotenv = new Dotenv\Dotenv(__DIR__);
		$dotenv->load();
		$dotenv->required(['MYSQL_USERNAME', 'MYSQL_PASSWORD', 'MYSQL_SERVER', 'S3_BUCKET', 'S3_ACCESS_KEY', 'S3_SECRET_KEY', 'S3_ENDPOINT']);
	}
	catch (Exception $e)
	{
		$out = new ConsoleOutput();
		$out->writeln('<error>' . $e->getMessage() . '</error>');
		exit;
	}
}