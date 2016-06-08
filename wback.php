#! /usr/bin/env php

<?php

use WBack\ListCommand;
use WBack\FilesCommand;
use WBack\DatabaseCommand;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

require 'vendor/autoload.php';

loadEnv();
$config = loadConfig();

$app = new Application('wback Website Backup', '1.0');

$list = new ListCommand(null, $config);
$app->add($list);
$app->add(new FilesCommand(null, $config));
$app->add(new DatabaseCommand(null, $config));

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

	if (!array_key_exists('app', $config)) fatal("missing app config");
	if (!array_key_exists('sources', $config)) fatal("missing source config");
	if (!isset($config['app']['backup_location']) OR !file_exists($config['app']['backup_location'])) fatal("backup destination path [{$config['app']['backup_location']}] does not exist");

	return $config;
}

function loadEnv()
{
	try
	{
		$dotenv = new Dotenv\Dotenv(__DIR__);
		$dotenv->load();
		$dotenv->required(['MYSQL_SERVER', 'S3_BUCKET', 'S3_ACCESS_KEY', 'S3_SECRET_KEY', 'S3_ENDPOINT']);
	}
	catch (Exception $e)
	{
		fatal($e->getMessage());
	}
}

function fatal($message)
{
	$out = new ConsoleOutput();
	$out->writeln("<error>Error: {$message}</error>", OutputInterface::VERBOSITY_QUIET);
	exit;
}