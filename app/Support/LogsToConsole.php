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
    use FormatsSizes;

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
    	// the fallback has to be a VERBOSITY_* constant, not the name of a level: line()
    	// puts an unrecognised string through parseVerbosity(), which knows v/vv/vvv/quiet/
    	// normal and nothing else, and falls back to whatever setVerbosity() last set
    	$verbosity = $verbosityMap[$level] ?? OutputInterface::VERBOSITY_NORMAL;
    	$style = $styleMap[$level] ?? null;

		Log::log($level, $logMessage, $context);
		$this->line($message, $style, $verbosity);
    }

    /**
     * Mask anything that looks like a credential in text on its way out
     *
     * The binary paths and the mysqldump and rclone option strings are inserted into
     * command lines as written, so a command line is the one place in this application
     * where a password could appear. `app:config` runs those settings through
     * console-report's redacted() for the same reason.
     *
     * It matters beyond the debug line that logs the command, which is the obvious
     * case: a process that FAILS puts its whole command line into the exception
     * message, and that message reaches the console, the log and - through
     * failureReason() - the Slack run summary. Found by running a backup with a
     * password in BACKUP_MYSQLDUMP_OPTIONS and grepping all three for it.
     *
     * Deliberately NOT console-report's redacted(): that one wraps its replacement in
     * console colour markup, which belongs in a terminal and not in a JSON field.
     *
     * @param string $text anything about to be printed, logged or sent
     * @return string the same, with credential-shaped values masked
     */
    protected function withoutSecrets(string $text) : string
    {
        return (string) preg_replace(
            ['/(--[\\w-]*(?:pass|secret|token)[\\w-]*[= ])\\S+/i', '/(^|\\s)(-p)\\S+/'],
            ['${1}redacted', '${1}${2}redacted'],
            $text
        );
    }

    /**
     * The same exception, or a masked stand-in when its message carried a credential
     *
     * An exception object in log context is what gives the JSON formatter a class and a
     * trace, so the real one goes in wherever it is safe - which is almost always. When
     * masking changes the message there IS a credential in it, and no amount of
     * formatting will take it out again, so a replacement carries the masked text
     * instead. The trace is lost in that case and the alternative is leaking the value.
     *
     * @param \Throwable $e the exception as caught
     * @return \Throwable it, or a masked stand-in
     */
    protected function safeException(\Throwable $e) : \Throwable
    {
        $safe = $this->withoutSecrets($e->getMessage());

        return $safe === $e->getMessage() ? $e : new \RuntimeException($safe, $e->getCode());
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
