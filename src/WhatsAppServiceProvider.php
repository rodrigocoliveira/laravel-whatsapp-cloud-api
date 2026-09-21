<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\ServiceProvider;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Console\Commands\FlowKeyCommand;
use Multek\LaravelWhatsAppCloud\Console\Commands\FlowTestCommand;
use Multek\LaravelWhatsAppCloud\Console\Commands\InstallCommand;
use Multek\LaravelWhatsAppCloud\Console\Commands\ProcessStaleBatchesCommand;
use Multek\LaravelWhatsAppCloud\Console\Commands\SyncTemplatesCommand;
use Multek\LaravelWhatsAppCloud\Contracts\MediaStorageInterface;
use Multek\LaravelWhatsAppCloud\Contracts\TranscriptionServiceInterface;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Observers\WhatsAppPhoneObserver;
use Multek\LaravelWhatsAppCloud\Services\MediaService;
use Multek\LaravelWhatsAppCloud\Services\TranscriptionService;
use Multek\LaravelWhatsAppCloud\Support\PricingCalculator;
use RuntimeException;

class WhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Whether the package's migrations should be auto-loaded from vendor/.
     * Disable via {@see self::ignoreMigrations()} if you already publish
     * and customize your own copies.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        static::$runsMigrations = false;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/whatsapp.php', 'whatsapp');

        $this->app->singleton(WhatsAppManager::class, function ($app) {
            return new WhatsAppManager($app);
        });

        $this->app->bind(WhatsAppClientInterface::class, WhatsAppClient::class);
        $this->app->bind(MediaStorageInterface::class, MediaService::class);
        $this->app->bind(TranscriptionServiceInterface::class, TranscriptionService::class);

        $this->app->bind(PricingCalculator::class, function ($app) {
            return new PricingCalculator($app['config']->get('whatsapp.pricing', []));
        });

        $this->app->alias(WhatsAppManager::class, 'whatsapp');
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->publishMigrations();
        $this->loadRoutes();
        $this->registerCommands();
        $this->registerSchedule();
        $this->registerObservers();
    }

    protected function registerObservers(): void
    {
        WhatsAppPhone::observe(WhatsAppPhoneObserver::class);
    }

    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/whatsapp.php' => config_path('whatsapp.php'),
        ], 'whatsapp-config');
    }

    protected function publishMigrations(): void
    {
        if (static::$runsMigrations) {
            $this->callAfterResolving('migrator', fn (Migrator $migrator) => $this->registerVendorMigrations($migrator));
        }

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'whatsapp-migrations');
    }

    /**
     * publishesMigrations() rewrites the date prefix, so to Laravel a published copy and its vendor
     * source are two migrations: copies still on disk keep precedence, deleted ones get their history adopted.
     */
    protected function registerVendorMigrations(Migrator $migrator): void
    {
        $vendor = $this->keyBySuffix(glob(__DIR__.'/../database/migrations/*_*.php') ?: []);
        $published = array_intersect_key($this->keyBySuffix(glob(database_path('migrations').'/*_*.php') ?: []), $vendor);
        $unpublished = array_diff_key($vendor, $published);

        if ($unpublished === []) {
            return;
        }

        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        $pendingCopies = array_diff(array_map($this->migrationName(...), $published), $ran);

        if ($pendingCopies !== []) {
            throw new RuntimeException(sprintf(
                'The published copies of the whatsapp migrations in %s have not run yet, but the package also ships %s, which Laravel would run before them. Delete the published copies (the package loads them from vendor/ and adopts any history recorded under the published names) or call %s::ignoreMigrations().',
                database_path('migrations'),
                implode(', ', array_map($this->migrationName(...), $unpublished)),
                static::class,
            ));
        }

        $this->adoptPublishedHistory($migrator, $unpublished, $ran);

        foreach ($unpublished as $file) {
            $migrator->path($file);
        }
    }

    /**
     * @param  array<string, string>  $vendor  suffix => file
     * @param  array<int, string>  $ran
     */
    protected function adoptPublishedHistory(Migrator $migrator, array $vendor, array $ran): void
    {
        $ranBySuffix = $this->keyBySuffix($ran);

        foreach ($vendor as $suffix => $file) {
            $name = $this->migrationName($file);
            $previous = $ranBySuffix[$suffix] ?? null;

            if ($previous === null || in_array($name, $ran, true)) {
                continue;
            }

            $migrator->resolveConnection($migrator->getConnection())
                ->table($this->migrationsTable())
                ->where('migration', $previous)
                ->update(['migration' => $name]);
        }
    }

    protected function migrationsTable(): string
    {
        $config = $this->app['config']->get('database.migrations', 'migrations');

        return is_array($config) ? ($config['table'] ?? 'migrations') : $config;
    }

    /**
     * @param  array<int, string>  $files
     * @return array<string, string> suffix => file
     */
    protected function keyBySuffix(array $files): array
    {
        $keyed = [];

        foreach ($files as $file) {
            $keyed[$this->migrationSuffix($file)] = $file;
        }

        return $keyed;
    }

    protected function migrationName(string $file): string
    {
        return basename($file, '.php');
    }

    protected function migrationSuffix(string $file): string
    {
        $name = $this->migrationName($file);

        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name) ?? $name;
    }

    protected function loadRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                SyncTemplatesCommand::class,
                ProcessStaleBatchesCommand::class,
                FlowKeyCommand::class,
                FlowTestCommand::class,
            ]);
        }
    }

    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('whatsapp:process-stale-batches')
                ->everyFiveMinutes()
                ->withoutOverlapping();
        });
    }
}
