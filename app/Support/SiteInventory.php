<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Yosymfony\Toml\Toml;

/**
 * The TOML file listing the sites to back up
 */
class SiteInventory
{
    public function path() : string
    {
        return config('backup.sites_path');
    }

    public function exists() : bool
    {
        return File::exists($this->path());
    }

    /**
     * @return array every site in the file, keyed by short name
     * @throws \Yosymfony\Toml\Exception\ParseException if the file will not parse
     */
    public function all() : array
    {
        if (!$this->exists())
        {
            return [];
        }

        return Toml::parseFile($this->path()) ?? [];
    }
}
