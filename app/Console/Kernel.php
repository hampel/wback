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
    	$schedule->command('backup:database --quiet --all')->name('Backup database')->twiceDaily(5, 17);
    	$schedule->command('backup:files --quiet --all')->name("Backup files")->twiceDaily(6, 18);
        $schedule->command('backup:s3 --quiet --all')->name("Backup S3")->twiceDaily(7, 19);
        $schedule->command('backup:sync --quiet --all')->name("Backup sync")->twiceDaily(8, 20);
        $schedule->command('backup:clean --quiet --all')->name("Clean up old backups")->dailyAt('04:00');
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
