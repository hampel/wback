<?php

namespace App\Commands;

use App\Support\LogsToConsole;
use LaravelZero\Framework\Commands\Command;

class Test extends Command
{
    use LogsToConsole;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test application configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        foreach ($levels as $level)
        {
            $this->log($level, "This is a test log message");
        }

        $this->line("Log messages written - please check your logs");
        $this->info("Logging configuration");
        $this->info("---------------------");

        $logging = collect(config('logging'))->dot();
        foreach ($logging as $key => $value)
        {
            $this->line("$key: $value");
        }

        return Command::SUCCESS;
    }

}
