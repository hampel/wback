<?php namespace WBack;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class S3Command extends BaseCommand
{
	public function configure()
	{
		$this->setName('s3')
			->setDescription('Copy backups to Amazon S3')
			->addArgument('source', InputArgument::OPTIONAL, 'Website source')
			->addOption('all', 'a', InputOption::VALUE_NONE, 'Process all sources')
			->addOption('dry-run', 'dr', InputOption::VALUE_NONE, "Dry run - don't actually do the work");
	}

	protected function processSource($name, array $source, InputInterface $input, OutputInterface $output)
	{
		$this->info("Sending files to S3 for source [{$name}]", $output);

		$config = $this->config['app'];

		$source_path = $config['backup_location'] . DIRECTORY_SEPARATOR . $source['url'] . DIRECTORY_SEPARATOR;
		if (!file_exists($source_path))
		{
			$this->error("source path [{$source_path}] not found for source [{$name}]", $output);
			return;
		}

		if (!array_key_exists('s3_bucket', $config) OR empty($config['s3_bucket']))
		{
			$this->error("s3_bucket not set", $output);
			return false;
		}

		$access_key = empty($config['s3_access_key']) ? '' : " --access_key={$config['s3_access_key']}";
		$secret_key = empty($config['s3_secret_key']) ? '' : " --secret_key={$config['s3_secret_key']}";
		$region = empty($config['s3_region']) ? '' : " --region={$config['s3_region']}";

		$destination_path = "s3://" . $config['s3_bucket'] . DIRECTORY_SEPARATOR . $source['url'] . DIRECTORY_SEPARATOR;

		$verbosity = '';
		if ($output->getVerbosity() < OutputInterface::VERBOSITY_NORMAL)
		{
			$verbosity = ' --quiet';
		}
		elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG)
		{
			$verbosity = ' --debug';
		}
		elseif ($output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL)
		{
			$verbosity = ' --verbose';
		}

		$cmd = $this->config['app']['s3cmd_path'] . " sync{$verbosity}{$region}{$access_key}{$secret_key} --reduced-redundancy {$source_path} {$destination_path}";

		$this->debug("executing command [{$cmd}]", $output);

		if ($input->getOption('dry-run'))
		{
			$this->comment("Dry run only - no backup performed", $output);
		}
		else
		{
			$retvar = 0;

			$command_output = system("{$cmd} 2>&1", $retvar);
			if ($retvar != 0)
			{
				$this->error("non-zero return code executing [{$cmd}]", $output);
				$this->error($command_output, $output);
			}
		}
	}
}
