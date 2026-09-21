<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Multek\LaravelWhatsAppCloud\WhatsAppServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Drives `migrate` by hand against a file-backed SQLite database that survives
 * refreshApplication(), so a test can act as a consumer who published the
 * migrations on one package version and then upgraded to another.
 */
abstract class MigrationScenarioTestCase extends BaseTestCase
{
    protected string $consumerRoot;

    protected function setUp(): void
    {
        $this->consumerRoot = sys_get_temp_dir().'/whatsapp-consumer-'.uniqid();

        (new Filesystem)->ensureDirectoryExists($this->consumerRoot.'/migrations');
        touch($this->consumerRoot.'/database.sqlite');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        WhatsAppServiceProvider::$runsMigrations = true;

        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->consumerRoot);
    }

    protected function getPackageProviders($app): array
    {
        return [WhatsAppServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => $this->consumerRoot.'/database.sqlite',
            'prefix' => '',
        ]);

        $app->useDatabasePath($this->consumerRoot);
    }

    /**
     * Run the consumer's pre-1.4.0 setup: the first $count vendor migrations
     * published (with the date prefix Laravel assigns at publish time) and
     * nothing loaded from vendor/.
     */
    protected function migrateAsPublishedConsumer(int $count): void
    {
        $this->publishVendorMigrations($count);

        WhatsAppServiceProvider::ignoreMigrations();
        $this->refreshApplication();

        Artisan::call('migrate');
    }

    protected function publishVendorMigrations(int $count): void
    {
        foreach (array_slice($this->vendorMigrations(), 0, $count) as $file) {
            copy($file, $this->consumerRoot.'/migrations/'.$this->publishedName($file).'.php');
        }
    }

    protected function deletePublishedMigrations(): void
    {
        (new Filesystem)->cleanDirectory($this->consumerRoot.'/migrations');
    }

    /**
     * Boot again with the package loading migrations from vendor/.
     */
    protected function upgradePackage(): void
    {
        WhatsAppServiceProvider::$runsMigrations = true;
        $this->refreshApplication();
    }

    /**
     * @return array<int, string>
     */
    protected function ranMigrations(): array
    {
        return DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    }

    /**
     * @return array<int, string>
     */
    protected function vendorMigrations(): array
    {
        return glob(__DIR__.'/../../database/migrations/*_*.php') ?: [];
    }

    /**
     * 2024_01_01_000001_create_x becomes 2026_02_06_235401_create_x: a publish-time
     * prefix that keeps the vendor sequence so the copies still run in order.
     */
    protected function publishedName(string $vendorFile): string
    {
        return preg_replace('/^2024_01_01_0000(\d{2})_/', '2026_02_06_2354$1_', basename($vendorFile, '.php'));
    }
}
