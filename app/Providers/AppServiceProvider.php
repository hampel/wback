<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (\Phar::running(false))
        {
            $this->app->useStoragePath(env('LARAVEL_STORAGE_PATH', getcwd()));
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
