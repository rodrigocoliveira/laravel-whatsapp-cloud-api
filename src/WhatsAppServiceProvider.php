<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Console\Commands\InstallCommand;
use Multek\LaravelWhatsAppCloud\Console\Commands\ProcessStaleBatchesCommand;
use Multek\LaravelWhatsAppCloud\Console\Commands\SyncTemplatesCommand;
use Multek\LaravelWhatsAppCloud\Contracts\MediaStorageInterface;
use Multek\LaravelWhatsAppCloud\Contracts\TranscriptionServiceInterface;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Observers\WhatsAppPhoneObserver;
use Multek\LaravelWhatsAppCloud\Services\MediaService;
use Multek\LaravelWhatsAppCloud\Services\TranscriptionService;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/whatsapp.php', 'whatsapp');

        $this->app->singleton(WhatsAppManager::class, function ($app) {
            return new WhatsAppManager($app);
        });

        $this->app->bind(WhatsAppClientInterface::class, WhatsAppClient::class);
        $this->app->bind(MediaStorageInterface::class, MediaService::class);
        $this->app->bind(TranscriptionServiceInterface::class, TranscriptionService::class);

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
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'whatsapp-migrations');
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
