<?php

namespace App\Support;

/**
 * Byte counts as a person reads them
 *
 * Shared rather than duplicated because a run summary and the log entries it summarises
 * have to agree: a total that rounds differently from the lines it adds up reads as an
 * arithmetic error.
 */
trait FormatsSizes
{
	protected function human_filesize($bytes, $dec = 2)
	{
	    $size   = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
	    $factor = floor((strlen($bytes) - 1) / 3);

	    return sprintf("%.{$dec}f", $bytes / pow(1024, $factor)) . " " . @$size[$factor];
	}
}
