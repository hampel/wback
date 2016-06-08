<?php namespace WBack;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FilesCommand extends BaseCommand
{
	public function configure()
	{
		$this->setName('files')
			->setDescription('Backup website files')
			->addArgument('source', InputArgument::OPTIONAL, 'Website source')
			->addOption('all', 'a', InputOption::VALUE_NONE, 'Process all sources')
			->addOption('dry-run', 'dr', InputOption::VALUE_NONE, "Dry run - don't actually do the work");
	}

	protected function processSource($name, array $source, InputInterface $input, OutputInterface $output)
	{
		if (!array_key_exists('url', $source))
		{
			$this->error("no url specified for source [{$name}]", $output);
			return;
		}

		if (!array_key_exists('files', $source))
		{
			$this->warning("no file path specified for source [{$name}]", $output);
			return;
		}

		$this->info("Processing files for source [{$name}]", $output);

		$source_path = $source['files'];

		if (!file_exists($source_path))
		{
			$this->error("file path [{$source_path}] does not exist for source [{$name}]", $output);
			return;
		}

		$backup_path = $this->config['app']['backup_location'];

		if (!file_exists($backup_path))
		{
			$this->error("backup destination path [{$backup_path}] does not exist", $output);
			return;
		}

		$files_folder = $this->config['app']['backup_location'] . DIRECTORY_SEPARATOR . "{$source['url']}/files";

		if (!file_exists($files_folder))
		{
			if (!mkdir($files_folder, 0775, true))
			{
				$this->error("could not create path [{$files_folder}] for source [{$name}]", $output);
				return;
			}
		}

		$ymd = date("Ymd", time());

		$zip_filename_base = "{$files_folder}" . DIRECTORY_SEPARATOR . "{$source['url']}-{$ymd}";
		$zip_filename = "{$zip_filename_base}.zip";
		$count = 1;
		while (file_exists($zip_filename)) {
			$count++;
			$zip_filename = "{$zip_filename_base}-{$count}.zip";
		}

		$output->writeln("Backing up files from [{$source_path}] to [{$zip_filename}]");

		$exclude = "";
		if (file_exists("{$source_path}" . DIRECTORY_SEPARATOR . $this->config['app']['zip_exclude_file'])) $exclude = " -x@" . $this->config['app']['zip_exclude_file'];

		$cmd = $this->config['app']['zip_path'] . " -r9qy {$zip_filename} .{$exclude}";

		$this->debug("executing command [{$cmd}]", $output);

		if ($input->getOption('dry-run'))
		{
			$this->comment("Dry run only - no backup performed", $output);
		}
		else
		{
			$command_output = '';
			$retvar = 0;

			$curdir = getcwd();
			chdir($source_path);

			exec("{$cmd} 2>&1", $command_output, $retvar);
			if ($retvar != 0)
			{
				$this->error("non-zero return code executing [{$cmd}]", $output);
				foreach ($command_output as $co)
				{
					$output->writeln("<error>$co</error>", OutputInterface::VERBOSITY_QUIET);
				}
			}
			chdir($curdir);
		}

	}
}
