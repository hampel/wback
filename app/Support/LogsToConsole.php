<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reporting that goes to the console and to the log at once
 *
 * The two are independent: the console message is gated by the level, so a --quiet run
 * shows errors only, while the log always gets the full detail along with its context.
 */
trait LogsToConsole
{
    protected function log($level, $message, $logMessage = null, $context = [])
    {
    	$verbosityMap = [
    	    'debug' => OutputInterface::VERBOSITY_DEBUG,
	        'info' => OutputInterface::VERBOSITY_VERBOSE,
	        'notice' => OutputInterface::VERBOSITY_NORMAL,
	        'warning' => OutputInterface::VERBOSITY_NORMAL,
	        'error' => OutputInterface::VERBOSITY_QUIET,
	        'critical' => OutputInterface::VERBOSITY_QUIET,
	        'alert' => OutputInterface::VERBOSITY_QUIET,
	        'emergency' => OutputInterface::VERBOSITY_QUIET,
	    ];

    	$styleMap = [
     	    'debug' => null,
	        'info' => 'info',
	        'notice' => 'comment',
	        'warning' => 'comment',
	        'error' => 'error',
	        'critical' => 'error',
	        'alert' => 'error',
	        'emergency' => 'error',
	    ];

    	$logMessage = $logMessage ?? $message;
    	$verbosity = $verbosityMap[$level] ?? 'warning';
    	$style = $styleMap[$level] ?? null;

		Log::log($level, $logMessage, $context);
		$this->line($message, $style, $verbosity);
    }

	protected function human_filesize($bytes, $dec = 2)
	{
	    $size   = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
	    $factor = floor((strlen($bytes) - 1) / 3);

	    return sprintf("%.{$dec}f", $bytes / pow(1024, $factor)) . " " . @$size[$factor];
	}

    protected function section($string, $verbosity = null)
    {
        if (! $this->output->getFormatter()->hasStyle('section')) {
            $style = new OutputFormatterStyle('cyan');

            $this->output->getFormatter()->setStyle('section', $style);
        }

        $this->output->newLine();
        $this->line($string, 'section', $verbosity);
        $this->line(str_repeat('-', strlen($string)), 'section', $verbosity);
        $this->output->newLine();
    }
}
