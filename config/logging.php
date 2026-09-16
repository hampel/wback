<?php

use App\Logging\StampHostname;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

// named once, because the slack channel labels its posts with it as well
$hostname = env('LOG_HOSTNAME', gethostname());

return [

    /*
    |--------------------------------------------------------------------------
    | Log Hostname
    |--------------------------------------------------------------------------
    |
    | What this machine calls itself in the logs, stamped onto every record by the
    | channels tapped with StampHostname below. A Slack channel that says which
    | host it is reporting on can serve every installation from one webhook,
    | instead of one webhook per machine to tell them apart.
    |
    | Set LOG_HOSTNAME to name the machine something more useful than it calls
    | itself, or to an empty value to leave records unstamped.
    |
    */

    'hostname' => $hostname,

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            // env() converts the literal words null, true, false and (null) before
            // this sees them, so LOG_STACK=null arrives as PHP null rather than the
            // name of the null channel. Cast and filter, or that leaves one nameless
            // channel: Laravel cannot build it, falls back to the emergency logger
            // and writes "Unable to create configured logger" into laravel.log - or
            // throws, where that path is not writable. The filter also absorbs an
            // empty value and a stray comma, and trim absorbs "single, slack".
            'channels' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('LOG_STACK', 'null'))
            ))) ?: ['null'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => env('LOG_STORAGE_PATH', storage_path('wback.log')),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [StampHostname::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => env('LOG_STORAGE_PATH', storage_path('wback.log')),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [StampHostname::class],
        ],

        // the username also lands in the footer of the Slack attachment, which is
        // shown whether or not the webhook is allowed to override the posting name.
        // With no hostname it falls back to app.name, the one place the tool's name
        // is set - config/app.php is loaded before this file, so it is there to read
        //
        // The level defaults to `error`, NOT to Laravel's stock `critical`. Every
        // failure this application reports is an ERROR record and nothing logs above
        // one, so a threshold of critical configures a channel that cannot fire - it
        // looks configured, passes every check, and stays silent on the night it was
        // installed for. `app:validate` warns when the threshold is set above `error`.
        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', $hostname ?: config('app.name')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_SLACK_LEVEL', 'error'),
            'replace_placeholders' => true,
            'tap' => [StampHostname::class],
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('laravel.log'),
        ],

    ],

];
