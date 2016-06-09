#! /usr/bin/env php

<?php

use WBack\S3Command;
use WBack\ListCommand;
use WBack\LogsCommand;
use WBack\CleanCommand;
use WBack\FilesCommand;
use WBack\DatabaseCommand;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

require 'vendor/autoload.php';

loadEnv();
$config = loadConfig();

$app = new Application('wback Website Backup', '1.2');
$app->setCatchExceptions(false);

$list = new ListCommand(null, $config);
$app->add($list);
$app->add(new FilesCommand(null, $config));
$app->add(new DatabaseCommand(null, $config));
$app->add(new LogsCommand(null, $config));
$app->add(new S3Command(null, $config));
$app->add(new CleanCommand(null, $config));

$app->setDefaultCommand($list->getName());

try
{
	$app->run();
}
catch (Exception $e)
{
	fatal($e->getMessage(), $e->getCode());
}


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

	$config['app']['keeponly_days'] = isset($config['app']['keeponly_days']) ? $config['app']['keeponly_days'] : 7;

	return $config;
}

function loadEnv()
{
	try
	{
		$dotenv = new Dotenv\Dotenv(__DIR__);
		$dotenv->load();
		$dotenv->required(['MYSQL_SERVER', 'S3_BUCKET_BACKUP', 'S3_BUCKET_SYNC']);
	}
	catch (Exception $e)
	{
		fatal($e->getMessage());
	}
}

function fatal($message, $exitcode = 0)
{
	$out = new ConsoleOutput();
	$out->writeln("<error>Error: {$message}</error>", OutputInterface::VERBOSITY_QUIET);

	if (is_numeric($exitcode)) {
		$exitCode = (int) $exitcode;
		if (0 === $exitcode) {
			$exitcode = 1;
		}
	} else {
		$exitcode = 1;
	}
	exit($exitcode);
}