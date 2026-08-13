<?php

use Dotenv\Dotenv;
use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();

/*
 * Where the environment file lives.
 *
 * A compiled binary has no project directory to keep one in, and the framework only
 * looks beside the binary - which means the configuration has to follow the executable
 * around. Looking further afield lets the binary live somewhere on the path with its
 * configuration alongside the rest of the system's, in /etc/wback.
 *
 * First of these that exists wins:
 *
 *   1. a .env beside the binary, which the framework loads last and so always wins
 *   2. WBACK_ENV, naming the file itself, for anywhere else entirely
 *   3. the project's own .env, when running from a source checkout
 *   4. /etc/wback/.env
 *
 * It is loaded here rather than left to the framework because the storage path below
 * is resolved before the application boots.
 */
$phar = \Phar::running(false);

$candidates = array_filter([
    $phar ? dirname($phar) . DIRECTORY_SEPARATOR . '.env' : null,
    getenv('WBACK_ENV') ?: null,
    $phar ? null : $app->basePath('.env'),
    DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'wback' . DIRECTORY_SEPARATOR . '.env',
]);

foreach ($candidates as $envFile)
{
    if (is_file($envFile))
    {
        $app->useEnvironmentPath(dirname($envFile));
        $app->loadEnvironmentFrom(basename($envFile));

        Dotenv::createMutable(dirname($envFile), basename($envFile))->safeLoad();

        break;
    }
}

if ($phar)
{
    $app->useStoragePath(env('LARAVEL_STORAGE_PATH', getcwd()));
}

return $app;
