<?php namespace WBack;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;


abstract class BaseCommand extends Command
{
	protected $config;

	public function __construct($name, array $config = [])
	{
		$this->config = $config;

		parent::__construct($name);
	}

	public function setConfig($config)
	{
		$this->config = $config;
	}

	protected function sourceExists($name, $sources)
	{
		return array_key_exists($name, $sources);
	}

	public function execute(InputInterface $input, OutputInterface $output)
	{
		$warning = new OutputFormatterStyle('white', 'yellow');
		$output->getFormatter()->setStyle('warning', $warning);

		$debug = new OutputFormatterStyle('cyan');
		$output->getFormatter()->setStyle('debug', $debug);

		if ($input->getOption('all'))
		{
			foreach ($this->config['sources'] as $name => $source)
			{
				if (!array_key_exists('url', $source))
				{
					$this->error("no url specified for source [{$name}]", $output);
					return;
				}

				$this->processSource($name, $source, $input, $output);
			}
		}
		else
		{
			$name = $input->getArgument('source');
			if (empty($name))
			{
				$this->error("no backup source specified", $output);
				return;
			}

			if (!$this->sourceExists($name, $this->config['sources']))
			{
				$this->error("could not find definition for source [{$name}]", $output);
				return;
			}

			if (!array_key_exists('url', $this->config['sources'][$name]))
			{
				$this->error("no url specified for source [{$name}]", $output);
				return;
			}

			$this->processSource($name, $this->config['sources'][$name], $input, $output);
		}
	}

	abstract protected function processSource($name, array $source, InputInterface $input, OutputInterface $output);

	protected function getDestination($name, $url, $type, OutputInterface $output)
	{
		$folder = $this->config['app']['backup_location'] . DIRECTORY_SEPARATOR . $url . DIRECTORY_SEPARATOR . $type;

		if (!file_exists($folder))
		{
			if (!mkdir($folder, 0775, true))
			{
				$this->error("could not create path [{$folder}] for source [{$name}]", $output);
				return false;
			}
		}

		return $folder;
	}

	protected function error($message, OutputInterface $output)
	{
		$output->writeln("<error>Error: {$message}</error>", OutputInterface::VERBOSITY_QUIET);
	}

	protected function warning($message, OutputInterface $output)
	{
		$output->writeln("<warning>Warning: {$message}</warning>", OutputInterface::VERBOSITY_NORMAL);
	}

	protected function info($message, OutputInterface $output)
	{
		$output->writeln("<info>{$message}</info>", OutputInterface::VERBOSITY_VERBOSE);
	}

	protected function comment($message, OutputInterface $output)
	{
		$output->writeln("<comment>{$message}</comment>", OutputInterface::VERBOSITY_NORMAL);
	}

	protected function debug($message, OutputInterface $output)
	{
		$output->writeln("<debug>Debug: {$message}</debug>", OutputInterface::VERBOSITY_DEBUG);
	}
}
