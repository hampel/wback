<?php

namespace App\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use LaravelZero\Framework\Commands\Command;
use Yosymfony\Toml\Exception\ParseException;
use Yosymfony\Toml\Toml;

class Sites extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sites {site?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List site backup configurations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $site = $this->argument('site');

        try
        {
            $sitesPath = config('backup.sites_path');
            $sites = File::exists($sitesPath) ? Toml::parseFile($sitesPath) : null;
        }
        catch (ParseException $e)
        {
            $message = $e->getMessage();
            Log::error($message);
            $this->error($message);
            return Command::FAILURE;
        }

        if (!empty($site))
        {
            $config = $sites[$site] ?? null;
            if (empty($config))
            {
                $this->error("Could not find definition for site: {$site}");
                return Command::FAILURE;
            }
            $this->outputSource($config);
        }
        else
        {
            if (empty($sites))
            {
                $this->error("No sites found at: " . config("backup.source_path"));
                return Command::FAILURE;
            }
            foreach ($sites as $name => $site)
            {
                $this->info($name);
                $this->outputSource($site);
            }
        }

        return Command::SUCCESS;
    }

    protected function outputSource(array $site) : void
    {
        foreach ($site as $key => $data)
        {
            if (!empty($data))
            {
                if (is_array($data))
                {
                    $this->line("    <comment>{$key}</comment>:");
                    foreach ($data as $d)
                    {
                        $this->line("        {$d}");
                    }
                }
                else
                {
                    $this->line("    <comment>{$key}</comment>: {$data}");
                }
            }
        }

        $this->line('');
    }
}
