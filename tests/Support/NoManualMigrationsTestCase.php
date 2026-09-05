<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Tests\Support;

use Multek\LaravelWhatsAppCloud\Tests\TestCase as BaseTestCase;

/**
 * A TestCase that does NOT manually load the package migrations, so tests
 * using it exercise only whatever WhatsAppServiceProvider itself registers.
 */
abstract class NoManualMigrationsTestCase extends BaseTestCase
{
    protected function defineDatabaseMigrations(): void
    {
        // Intentionally empty.
    }
}
