<?php

namespace App\Providers;

use App\Support\BackupLock;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * app:build evaluates config/app.php on the build machine and compiles the
         * result in as literals, so an env() call in that file is frozen at build time.
         * The timezone is configured in config/backup.php instead, which is compiled as
         * written, and applied here - before any command runs.
         */
        $timezone = config('backup.timezone');

        config(['app.timezone' => $timezone]);

        date_default_timezone_set($timezone);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // shared, so the commands the cron command runs can see that it already holds
        // the lock - flock conflicts with itself when one process opens the file twice
        $this->app->singleton(BackupLock::class);
    }
}
