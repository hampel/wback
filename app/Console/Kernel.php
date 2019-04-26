<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
    	// TODO: make schedule configurable (twiceDaily ?)
    	$schedule->command('backup:database --quiet --all')->name('Backup database')->daily();
    	$schedule->command('backup:files --quiet --all')->name("Backup files")->daily();
        $schedule->command('backup:s3 --quiet --all')->name("Backup S3")->daily();
        $schedule->command('backup:sync --quiet --all')->name("Backup sync")->daily();
//        $schedule->command('backup:clean --quiet --all')->name("Clean up backups")->daily();
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
