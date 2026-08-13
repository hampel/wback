<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->useStoragePath($this->testStoragePath());

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Scratch storage for this test process.
     *
     * Faked disks are real directories underneath storage_path(), and the
     * commands need real paths to build their shell commands from - so we point
     * storage somewhere disposable rather than leaving the trees behind in the
     * project.
     */
    protected function testStoragePath(): string
    {
        static $path;

        if ($path === null) {
            $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wback-tests-'.getmypid();

            $filesystem = new Filesystem;
            $filesystem->deleteDirectory($path);
            $filesystem->ensureDirectoryExists($path);

            register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($path));
        }

        return $path;
    }
}
