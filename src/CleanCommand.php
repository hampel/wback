<?php namespace WBack;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanCommand extends BaseCommand
{
	public function configure()
	{
		$this->setName('clean')
			->setDescription('Clean up old files from Amazon S3')
			->addArgument('source', InputArgument::OPTIONAL, 'Website source')
			->addOption('all', 'a', InputOption::VALUE_NONE, 'Process all sources')
			->addOption('dry-run', 'dr', InputOption::VALUE_NONE, "Dry run - don't actually do the work");
	}

	protected function processSource($name, array $source, InputInterface $input, OutputInterface $output)
	{
		$config = $this->config['app'];

		$this->info("Cleaning up source [{$name}] - removing files older than {$config['keeponly_days']} days", $output);

		$backup_location = $config['backup_location'] . DIRECTORY_SEPARATOR . $source['url'] . DIRECTORY_SEPARATOR;
		if (!file_exists($backup_location))
		{
			$this->error("backup directory [{$backup_location}] not found for source [{$name}]", $output);
			return;
		}

		if (!array_key_exists('files', $source))
		{
			$this->warning("no file path specified for source [{$name}]", $output);
			return;
		}

		if ($input->getOption('dry-run'))
		{
			$this->comment("Dry run only - no files will be removed", $output);
		}

		foreach (['files', 'database', 'logs'] as $type)
		{
			$this->cleanup($backup_location, $name, $type, $config['keeponly_days'], $input, $output);
		}
	}

	private function cleanup($base, $name, $type, $keeponly_days, InputInterface $input, OutputInterface $output)
	{
		$path = $base . $type;
		if (!file_exists($path))
		{
			$this->info("skipping non-existent path [{$path}] for source [{$name}]", $output);
			return;
		}

		$date = "before {$keeponly_days} days ago";
		$count = 0;

		foreach (Finder::create()->files()->date($date)->in($path) as $file) {
			$dryrun = $input->getOption('dry-run') ? 'not ' : '';
			$this->info(sprintf("%sdeleting [%s]", $dryrun, $file->getRealPath()), $output);
			$this->debug(sprintf("[%s] last modified: [%s]", $file->getBasename(), date("r", $file->getMTime())), $output);

			if ($input->getOption('dry-run'))
			{
				$count++; // count files that would have been deleted
			}
			else
			{
				if (!unlink($file->getRealPath()))
				{
					$this->error(sprintf("could not delete [%s]", $file->getRealPath()), $output);
				}
				else
				{
					$count++;
				}
			}
		}

		$dryrun = $input->getOption('dry-run') ? ' would have been' : '';
		if ($count > 0)	$this->info(sprintf("%d files%s removed from [%s]", $count, $dryrun, $path), $output);
	}
}
