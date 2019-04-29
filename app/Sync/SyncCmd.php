<?php namespace App\Sync;

use Illuminate\Console\OutputStyle;

interface SyncCmd
{
	public function buildCmd($source, $destination, $path, $dryrun = false, OutputStyle $output) : string;

	public function canDryRun() : bool;
}
