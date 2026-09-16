<?php

use App\Kernel;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();

/*
 * Rebind the console kernel over the one Application::configure() just bound, so an
 * unrecognised command name fails instead of being proxied to the summary and exiting
 * 0. App\Kernel says why that matters here.
 */
$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    Kernel::class
);

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

$loaded = null;

foreach ($candidates as $envFile)
{
    if (is_file($envFile))
    {
        $app->useEnvironmentPath(dirname($envFile));
        $app->loadEnvironmentFrom(basename($envFile));

        /*
         * safeLoad() swallows a MISSING file, not an unparseable one, so this catch is
         * the difference between a message and a stack trace. It must never print the
         * exception: Dotenv puts the offending line INTO the message, and a line that
         * will not parse is exactly the kind that carries a credential - the documented
         * way to pass mysqldump a password is an option string, and an unquoted space
         * in one is what breaks the parse. Under cron the whole thing gets mailed.
         *
         * So report WHERE and not WHAT: the file, and the line number recovered by
         * looking for the fragment rather than by printing it.
         */
        try
        {
            Dotenv::createMutable(dirname($envFile), basename($envFile))->safeLoad();
        }
        catch (InvalidFileException $e)
        {
            $line = null;

            if (preg_match('/\[(.+)\]/', $e->getMessage(), $matches))
            {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $index => $text)
                {
                    if (str_contains($text, $matches[1]))
                    {
                        $line = $index + 1;
                        break;
                    }
                }
            }

            fwrite(STDERR, sprintf(
                'wback: could not parse the environment file %s%s.%s',
                $envFile,
                $line ? ", at line {$line}" : '',
                PHP_EOL
            ));
            fwrite(STDERR, 'A value containing spaces has to be quoted.' . PHP_EOL);

            exit(1);
        }

        $loaded = $envFile;

        break;
    }
}

/*
 * Recorded so app:config can report the file that was actually read, and say where it
 * looked when there was none. Left to the framework, environmentFilePath() answers with
 * base_path().'/.env' whether or not anything is there - which inside a phar names a
 * file in the archive that has never been opened, and reads exactly like a real answer.
 */
$app->instance('wback.env.loaded', $loaded);
$app->instance('wback.env.candidates', array_values($candidates));

if ($phar)
{
    $app->useStoragePath(env('LARAVEL_STORAGE_PATH', getcwd()));
}

return $app;
