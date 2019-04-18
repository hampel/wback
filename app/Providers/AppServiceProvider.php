<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Yosymfony\Toml\Toml;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
//    	dump(config('backup.sources.lookup'));
//    	dump(config('backup.sources.helpdesk'));
//        $source_path = config('backup.sources');
//        $this->app->config['backup.sources'] = Toml::parseFile(config('backup.sources_path'))
    }
}
