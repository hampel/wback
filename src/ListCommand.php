<?php namespace WBack;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\ListCommand as BaseListCommand;

class ListCommand extends BaseListCommand
{
	protected $config;

	public function __construct($name, array $config = [])
	{
		$this->config = $config;

		parent::__construct($name);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		parent::execute($input, $output);

		$output->writeln('');
		$output->writeln('<comment>Available Sources:</comment>');

		if (!isset($this->config['sources']))
		{
			$output->writeln('<error>Error in source configuration</error>');
			return;
		}

        $table = new Table($output);

		foreach($this->config['sources'] as $name => $source)
		{
			$table->addRow(["<info>{$name}</info>", "({$source['url']})"]);
		}

		$style = new TableStyle();
		$style->setBorderFormat('');
		$style->setCellHeaderFormat('');
		$style->setCellRowFormat('  %s');
		$style->setCellRowContentFormat('%s');

		$table->setStyle($style);
		$table->render();

	}
}

?>
