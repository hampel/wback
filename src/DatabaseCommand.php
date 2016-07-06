<?php namespace WBack;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DatabaseCommand extends BaseCommand
{
	public function configure()
	{
		$this->setName('database')
			->setDescription('Backup website databases')
			->setAliases(['db'])
			->addArgument('source', InputArgument::OPTIONAL, 'Website source')
			->addOption('all', 'a', InputOption::VALUE_NONE, 'Process all sources')
			->addOption('dry-run', 'dr', InputOption::VALUE_NONE, "Dry run - don't actually do the work");
	}

	protected function processSource($name, array $source, InputInterface $input, OutputInterface $output)
	{
		if (!array_key_exists('database', $source) OR empty($source['database']))
		{
			$this->warning("no database specified for source [{$name}]", $output);
			return;
		}

		$this->info("Processing database for source [{$name}]", $output);

		if (!$database_folder = $this->getDestination($name, $source['url'], 'database', $output)) return;

		$ymd = date("Ymd", time());
		$db = $source['database'];

		$gz_filename_base = "{$database_folder}" . DIRECTORY_SEPARATOR . "{$db}-{$ymd}";
		$gz_filename = "{$gz_filename_base}.sql.gz";
		$count = 1;
		while (file_exists($gz_filename))
		{
			$count++;
			$gz_filename = "{$gz_filename_base}-{$count}.sql.gz";
		}

		$output->writeln("Backing up database [{$db}] to [{$gz_filename}]");

		if (!$my = $this->getMysqlConfig($output)) return;

		$username = empty($my['username']) ? '' : " -u{$my['username']}";
		$password = empty($my['password']) ? '' : " -p{$my['password']}";

		$hostname = '';
		$hostname = !empty($source['hostname']) ? " -h{$source['hostname']}" : $hostname;
		$hostname = empty($hostname) && !empty($my['server']) ? " -h{$my['server']}" : $hostname;

		$verbosity = '';
		if ($output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL)
		{
			$verbosity = ' --verbose';
		}

		$cmd = "{$my['path']} --opt{$verbosity}{$username}{$password}{$hostname} {$db} | gzip -c -f > {$gz_filename}";

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

	private function getMysqlConfig(OutputInterface $output)
	{
		$config = $this->config['app'];

		if (!array_key_exists('mysqldump_path', $config) OR empty($config['mysqldump_path']))
		{
			$this->error("mysqldump_path not set", $output);
			return false;
		}
		if (!array_key_exists('mysql_server', $config) OR empty($config['mysql_server']))
		{
			$this->error("mysql_server not set", $output);
			return false;
		}

		return [
			'path' => $config['mysqldump_path'],
			'username' => $config['mysql_username'],
			'password' => $config['mysql_password'],
			'server' => $config['mysql_server']
		];
	}
}
