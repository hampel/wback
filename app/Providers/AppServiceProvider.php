<?php

namespace App\Providers;

use App\Sync\SyncCmd;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
    	$sync_service = config('sync.default');
        $this->app->bind(SyncCmd::class, config("sync.services.{$sync_service}.builder"));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
		//
    }
}
