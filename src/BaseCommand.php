<?php namespace WBack;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


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
		if ($input->getOption('all'))
		{
			foreach ($this->config['sources'] as $name => $source)
			{
				$this->processSource($name, $source, $input, $output);
			}
		}
		else
		{
			$name = $input->getArgument('source');
			if (empty($name))
			{
				$output->writeln('<error>Error: no backup source specified</error>');
				return;
			}

			if (!$this->sourceExists($name, $this->config['sources']))
			{
				$output->writeln("<error>Error: could not find source definition for [{$name}]</error>");
				return;
			}

			$this->processSource($name, $this->config['sources'][$name], $input, $output);
		}
	}

	abstract protected function processSource($name, array $source, InputInterface $input, OutputInterface $output);
}
