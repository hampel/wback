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
			->addOption('dry-run', 'dr', InputOption::VALUE_NONE, 'Dry run - don\'t actually do the work');
	}

	protected function processSource($name, array $source, InputInterface $input, OutputInterface $output)
	{
		if (!array_key_exists('url', $source))
		{
			$output->writeln("<error>Error: no source url specified for [{$name}]</error>");
			return;
		}

		if (!array_key_exists('files', $source))
		{
			$output->writeln("<error>Error: no source file path specified for [{$name}]</error>");
			return;
		}

		$output->writeln("<info>Processing source: {$name}</info>");

		$ymd = date("Ymd", time());

		$source_path = $source['files'];

		if (!file_exists($source_path))
		{
			$output->writeln("<error>Error: source file path [{$source_path}] does not exist for [{$name}]</error>");
			return;
		}

		$backup_path = $this->config['app']['backup_location'];

		if (!file_exists($backup_path))
		{
			$output->writeln("<error>Error: backup destination path [{$backup_path}] does not exist</error>");
			return;
		}

		$files_folder = $this->config['app']['backup_location'] . DIRECTORY_SEPARATOR . "{$source['url']}/files";

		if (!file_exists($files_folder))
		{
			if (!mkdir($files_folder, 0775, true))
			{
				$output->writeln("<error>Could not create path [{$files_folder}]</error>");
				return;
			}
		}

		$zip_filename_base = "{$files_folder}" . DIRECTORY_SEPARATOR . "{$source['url']}-{$ymd}";
		$zip_filename = "{$zip_filename_base}.zip";
		$count = 1;
		while (file_exists($zip_filename)) {
			$count++;
			$zip_filename = "{$zip_filename_base}-{$count}.zip";
		}

		$output->writeln("Backing up files from {$source_path} to {$zip_filename}");

		$exclude = "";
		if (file_exists("{$source_path}" . DIRECTORY_SEPARATOR . $this->config['app']['zip_exclude_file'])) $exclude = " -x@" . $this->config['app']['zip_exclude_file'];

		$cmd = $this->config['app']['zip_path'] . " -r9qy {$zip_filename} .{$exclude}";

		$output->writeln("Executing: {$cmd}", OutputInterface::VERBOSITY_DEBUG);

		if ($input->getOption('dry-run'))
		{
			echo "{$cmd}" . PHP_EOL;
		}
		else
		{
			$command_output = '';
			$retvar = 0;

			$curdir = getcwd();
			chdir($source_path);

			exec($cmd, $command_output, $retvar);
			if ($retvar != 0)
			{
				$output->writeln("Error executing: {$cmd}");
				if (!empty($command_output)) $output->writeln($command_output);
			}
			chdir($curdir);
		}

	}
}
