<?php

namespace App\Providers;

use App\Support\BackupLock;
use App\Support\RunSummary;
use GuzzleHttp\Client;
use Hampel\SlackMessage\SlackWebhook;
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

        // shared for the same reason: the stages are separate command objects, and the
        // run is the thing being summarised rather than any one of them
        $this->app->singleton(RunSummary::class);

        // bound rather than constructed where it is used, so a test can hand the sender
        // a client that answers without a network
        //
        // http_errors off because Slack reports a refusal two different ways - a status
        // from a webhook, an "ok" field from the api - and the sender reads both. The
        // timeouts are there because this runs at the tail of a backup: a webhook that
        // has stopped answering should cost the run seconds, not the rest of the night.
        $this->app->singleton(SlackWebhook::class, fn () => new SlackWebhook(new Client([
            'http_errors' => false,
            'connect_timeout' => 5,
            'timeout' => 15,
        ])));
    }
}
