<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // TODO: make schedule configurable (twiceDaily ?)
        $schedule->command('backup:database --quiet --all')->name('Backup database')->dailyAt("3:00");
        $schedule->command('backup:files --quiet --all')->name("Backup files")->dailyAt("4:00");
        $schedule->command('backup:cloud --quiet --all')->name("Send backup files to the cloud")->dailyAt("5:00");
        $schedule->command('backup:sync --quiet --all')->name("Cloud sync")->dailyAt("6:00");
        $schedule->command('backup:clean --quiet --all')->name("Clean up old backups")->dailyAt("7:00");
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
