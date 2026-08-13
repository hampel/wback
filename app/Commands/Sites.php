<?php

namespace App\Commands;

use App\Support\SiteInventory;
use Illuminate\Support\Facades\Log;
use LaravelZero\Framework\Commands\Command;
use Yosymfony\Toml\Exception\ParseException;

class Sites extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sites {site?}';

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

        $inventory = app(SiteInventory::class);

        try
        {
            $sites = $inventory->all();
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
                $this->error("No sites found at: " . $inventory->path());
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
            if (is_array($data) && !empty($data))
            {
                $this->line("    <comment>{$key}</comment>:");
                foreach ($data as $d)
                {
                    $this->line("        {$d}");
                }

                continue;
            }

            $this->line("    <comment>{$key}</comment>: " . $this->formatValue($data));
        }

        $this->line('');
    }

    /**
     * A key turned off carries as much meaning as one that is set - database = ''
     * means this site has no database - so every key in the file is shown, empty or
     * not. A key that is absent from the file is the one that is not listed, which is
     * the difference between deliberately disabled and left to the default.
     */
    protected function formatValue($data) : string
    {
        return match (true) {
            is_bool($data) => $data ? 'true' : 'false',
            is_array($data), $data === null, $data === '' => '(none)',
            default => (string) $data,
        };
    }
}
