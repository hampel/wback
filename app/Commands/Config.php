<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use LaravelZero\Framework\Commands\Command;

class Config extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config {--only= : The section to display}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show application configuration';

    /**
     * The data to display.
     *
     * @var array
     */
    protected static $data = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        static::addToSection('Application', fn () => [
            'Name' => config('app.name'),
            'Version' => $this->app->version(),
            'Laravel Version' => $this->app::VERSION,
            'PHP Version' => phpversion(),
            'Environment' => $this->laravel->environment(),
            'Debug Mode' => config('app.debug') ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF',
            'Timezone' => config('app.timezone'),
        ]);

        static::addToSection('Backup', fn () => [
            'Sites Path' => config('backup.sites_path'),
            'MySQL Dump Path' => config('backup.mysql.dump_path'),
            'MySQL Default Charset' => config('backup.mysql.default_charset'),
            'MySQL Hex Blob' => config('backup.mysql.hexblob') ? 'true' : 'false',
            'GZip Path' => config('backup.gzip_path'),
            'Zip Path' => config('backup.zip_path'),
            'Keep Only Days' => config('backup.keep_only_days'),
            'rClone Remote' => config('backup.rclone_remote'),
            'Schedule Start' => config('backup.schedule_start'),
        ]);

        static::addToSection('Filesystems', fn () => [
            'Default' => config('filesystems.default'),
            'Storage Path' => storage_path(),
            'Files Disk' => config('filesystems.disks.files.root'),
            'Backup Disk' => config('filesystems.disks.backup.root'),
        ]);

        static::addToSection('Logging', fn () => [
            'Default' => config('logging.default'),
            'Stack Channels' => implode(',', config('logging.channels.stack.channels')),
            'Single Path' => config('logging.channels.single.path'),
            'Single Level' => config('logging.channels.single.level'),
        ]);

        collect(static::$data)
            ->map(fn ($items) => collect($items)
                ->map(function ($value) {
                    if (is_array($value)) {
                        return [$value];
                    }

                    if (is_string($value)) {
                        $value = $this->laravel->make($value);
                    }

                    return collect($this->laravel->call($value))
                        ->map(fn ($value, $key) => [$key, $value])
                        ->values()
                        ->all();
                })->flatten(1)
            )
            ->sortBy(function ($data, $key) {
                $index = array_search($key, ['Application', 'Backup', 'Filesystems', 'Logging']);

                return $index === false ? 99 : $index;
            })
            ->filter(function ($data, $key) {
                return $this->option('only') ? in_array($this->toSearchKeyword($key), $this->sections()) : true;
            })
            ->pipe(fn ($data) => $this->display($data));

        $this->newLine();

        return Command::SUCCESS;
    }

    /**
     * Display the application information.
     *
     * @param  \Illuminate\Support\Collection  $data
     * @return void
     */
    protected function display($data)
    {
        $this->displayDetail($data);
    }

    protected function displayDetail($data)
    {
        $data->each(function ($data, $section) {
            $this->newLine();

            $this->components->twoColumnDetail('  <fg=green;options=bold>'.$section.'</>');

            $data->pipe(fn ($data) => $data)->each(function ($detail) {
                [$label, $value] = $detail;

                $this->components->twoColumnDetail($label, value($value));
            });
        });
    }

    /**
     * Add additional data to the output of the "about" command.
     *
     * @param  string  $section
     * @param  callable|string|array  $data
     * @param  string|null  $value
     * @return void
     */
    public static function add(string $section, $data, string $value = null)
    {
        static::$customDataResolvers[] = fn () => static::addToSection($section, $data, $value);
    }

    protected static function addToSection(string $section, $data, string $value = null)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                self::$data[$section][] = [$key, $value];
            }
        } elseif (is_callable($data) || ($value === null && class_exists($data))) {
            self::$data[$section][] = $data;
        } else {
            self::$data[$section][] = [$data, $value];
        }
    }

    protected function sections()
    {
        return collect(explode(',', $this->option('only') ?? ''))
            ->filter()
            ->map(fn ($only) => $this->toSearchKeyword($only))
            ->all();
    }

    /**
     * Format the given string for searching.
     *
     * @param  string  $value
     * @return string
     */
    protected function toSearchKeyword(string $value)
    {
        return (new Stringable($value))->lower()->snake()->value();
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
