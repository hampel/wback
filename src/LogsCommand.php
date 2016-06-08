<?php namespace WBack;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LogsCommand extends BaseCommand
{
	public function configure()
	{
		$this->setName('logs')
			->setDescription('Backup website logs')
			->addArgument('source', InputArgument::OPTIONAL, 'Website source')
			->addOption('all', 'a', InputOption::VALUE_NONE, 'Process all sources')
			->addOption('dry-run', 'dr', InputOption::VALUE_NONE, "Dry run - don't actually do the work");
	}

	protected function processSource($name, array $source, InputInterface $input, OutputInterface $output)
	{
		$access_log = '';
		$error_log = '';

		$pid_path = $this->config['app']['webserver_pid_path'];
		if (!file_exists($pid_path))
		{
			$this->error("webserver PID path [{$pid_path}] does not exist", $output);
			return;
		}

		if (!$logs_folder = $this->getDestination($name, $source['url'], 'logs', $output)) return;

		if (!array_key_exists('access', $source) OR empty($source['access']))
		{
			$this->warning("no access log path specified for source [{$name}]", $output);
			$source['access'] = '';
		}
		else
		{
			$access_log = $this->processLogs($name, $source['access'], $source['url'], 'access', $logs_folder, $input, $output);
		}

		if (!array_key_exists('error', $source) OR empty($source['error']))
		{
			$this->warning("no error log path specified for source [{$name}]", $output);
			$source['error'] = '';
		}
		else
		{
			$error_log = $this->processLogs($name, $source['error'], $source['url'], 'error', $logs_folder, $input, $output);
		}

		if (empty($source['access']) AND empty($source['error']))
		{
			$this->info("nothing to do for source [{$name}]", $output);
			return;
		}

		$this->rotateLogs($pid_path, $input, $output);

		if (!empty($access_log)) $this->compressLogs($name, $access_log, 'access', $input, $output);
		if (!empty($error_log)) $this->compressLogs($name, $error_log, 'error', $input, $output);
	}

	private function processLogs($name, $log_path, $url, $type, $destination, InputInterface $input, OutputInterface $output)
	{
		$this->info("Processing {$type} logs for source [{$name}]", $output);

		if (!file_exists($log_path))
		{
			$this->error("{$type} log path [{$log_path}] does not exist for source [{$name}]", $output);
			return;
		}

		$ymd = date("Ymd", time());

		$log_filename_base = $destination . DIRECTORY_SEPARATOR . "{$url}-{$type}-{$ymd}";
		$dest_filename = "{$log_filename_base}";
		$count = 1;
		while (file_exists($dest_filename)) {
			$count++;
			$dest_filename = "{$log_filename_base}-{$count}";
		}

		$gz_filename = "{$log_filename_base}.gz";
		while (file_exists($gz_filename)) {
			$count++;
			$gz_filename = "{$log_filename_base}-{$count}.gz";
			$dest_filename = "{$log_filename_base}-{$count}";
		}

		$output->writeln("Moving logs from [{$log_path}] to [{$dest_filename}]");

		$verbosity = '';
		if ($output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL)
		{
			$verbosity = ' --verbose';
		}

		$cmd = "mv{$verbosity} {$log_path} {$dest_filename}";

		$this->debug("executing command [{$cmd}]", $output);

		if ($input->getOption('dry-run'))
		{
			$this->comment("Dry run only - no backup performed", $output);
		}
		else
		{
			$command_output = '';
			$retvar = 0;

			exec("{$cmd} 2>&1", $command_output, $retvar);
			if ($retvar != 0)
			{
				$this->error("non-zero return code executing [{$cmd}]", $output);
				foreach ($command_output as $co)
				{
					$output->writeln("<error>$co</error>", OutputInterface::VERBOSITY_QUIET);
				}
			}
		}

		return $dest_filename;
	}

	private function rotateLogs($pid_path, InputInterface $input, OutputInterface $output)
	{
		$this->info("Instructing web server to open new log files", $output);

		$cmd = "kill -USR1 `cat {$pid_path}`";

		$this->debug("executing command [{$cmd}]", $output);

		if (!$input->getOption('dry-run'))
		{
			$command_output = '';
			$retvar = 0;

			exec("{$cmd} 2>&1", $command_output, $retvar);
			if ($retvar != 0)
			{
				$this->error("non-zero return code executing [{$cmd}]", $output);
				foreach ($command_output as $co)
				{
					$output->writeln("<error>$co</error>", OutputInterface::VERBOSITY_QUIET);
				}
			}
			sleep(1);
		}
	}

	private function compressLogs($name, $logpath, $type, InputInterface $input, OutputInterface $output)
	{
		$this->info("Compressing {$type} logs for source [{$name}]", $output);

		$verbosity = '';
		if ($output->getVerbosity() < OutputInterface::VERBOSITY_NORMAL)
		{
			$verbosity = ' --quiet';
		}
		elseif ($output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL)
		{
			$verbosity = ' --verbose';
		}

		$cmd = "gzip{$verbosity} {$logpath}";

		$this->debug("executing command [{$cmd}]", $output);

		if (!$input->getOption('dry-run'))
		{
			$command_output = '';
			$retvar = 0;

			exec("{$cmd} 2>&1", $command_output, $retvar);
			if ($retvar != 0)
			{
				$this->error("non-zero return code executing [{$cmd}]", $output);
				foreach ($command_output as $co)
				{
					$output->writeln("<error>$co</error>", OutputInterface::VERBOSITY_QUIET);
				}
			}
		}
	}
}
