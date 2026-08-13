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
        //
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
