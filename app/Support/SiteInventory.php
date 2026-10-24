<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Yosymfony\Toml\Exception\ParseException;
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

    /**
     * The line a parse error points at, without the rest of its message.
     *
     * The message can quote text from the file: an unquoted value is reported as
     * `... with value "<the value>"`, so it carries whatever was typed there, which
     * is why the message goes to the console and never to the log. The line is what
     * fixing the file needs, and it is all the log gets.
     *
     * Read out of the message because yosymfony never fills in getParsedLine() - it
     * returned -1 for every failure tried. Only the digits are captured. Null where
     * the message names no line, as for a duplicate key or a duplicate table. Not
     * getLine() either: that is the line of PHP in the library that threw.
     */
    public static function errorLine(ParseException $e) : ?int
    {
        return preg_match('/\bat line (\d+)\b/', $e->getMessage(), $matches)
            ? (int) $matches[1]
            : null;
    }
}
