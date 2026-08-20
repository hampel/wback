<?php

namespace App\Support;

/**
 * Picking the one line worth quoting out of what a failed command printed
 *
 * Tools warn before they fail - mysqldump leads with a note about ssl verification
 * before telling you the connection was refused - so the first line of the output is
 * often not the reason for the failure. Shared between the validation report and the
 * run summary, which both have one line to spend on saying what went wrong and send
 * the reader to the log for the rest.
 */
trait ReadsCommandOutput
{
    /**
     * @param string $output output of a failed command
     * @return string the first line that is not a warning, if there is one
     */
    protected function firstLine(string $output) : string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($line) => $line !== ''));

        foreach ($lines as $line)
        {
            if (!preg_match('/^warning\b/i', $line))
            {
                return $line;
            }
        }

        return $lines[0] ?? '(no output)';
    }
}
