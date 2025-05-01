<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Sources extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sources {source?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List backup sources';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $source = $this->argument('source');

        if (!empty($source))
        {
            $config = config("backup.sources.{$source}");
            if (empty($config))
            {
                $this->error("Could not find definition for source: {$source}");
                return Command::FAILURE;
            }
            $this->outputSource($config);
        }
        else
        {
            $sources = config("backup.sources");
            if (empty($sources))
            {
                $this->error("No sources found at: " . config("backup.source_path"));
                return Command::FAILURE;
            }
            foreach ($sources as $name => $source)
            {
                $this->info($name);
                $this->outputSource($source);
            }
        }

        return Command::SUCCESS;
    }

    protected function outputSource($source)
    {
        foreach ($source as $key => $data)
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
