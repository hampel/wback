<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

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

        if (!empty($site))
        {
            $config = config("backup.sites.{$site}");
            if (empty($config))
            {
                $this->error("Could not find definition for site: {$site}");
                return Command::FAILURE;
            }
            $this->outputSource($config);
        }
        else
        {
            $sites = config("backup.sites");
            if (empty($sites))
            {
                $this->error("No sources found at: " . config("backup.source_path"));
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
