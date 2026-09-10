<?php

namespace App\Providers;

use App\Support\BackupLock;
use App\Support\RunSummary;
use App\Support\SlackSummary;
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
         * The timezone is backup.timezone, from config/backup.php, which app:build
         * compiles as written - unlike config/app.php, whose values it freezes at build
         * time and which therefore has no timezone key. The application reads
         * backup.timezone directly. This mirrors it into app.timezone and PHP's default
         * for the framework and anything relying on the default, before any command runs
         * - once, which is why nothing in app/ should read the copy.
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

        // the reporter is handed what it needs rather than reading it, so that the same
        // class works somewhere config() and app() do not exist - reading configuration
        // is this application's job, reporting is its own
        $this->app->singleton(SlackSummary::class, fn () => new SlackSummary(
            $this->app->make(SlackWebhook::class),
            (string) config('backup.summary.slack_webhook'),
            (string) config('backup.summary.notify'),
            config('app.name') . ' ' . $this->app->version(),
            (string) (config('logging.hostname') ?: gethostname())
        ));
    }
}
