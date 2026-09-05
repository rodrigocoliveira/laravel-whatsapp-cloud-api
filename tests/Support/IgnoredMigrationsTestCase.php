<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Tests\Support;

use Multek\LaravelWhatsAppCloud\WhatsAppServiceProvider;

/**
 * Boots the app with WhatsAppServiceProvider::ignoreMigrations() already
 * called, simulating a consumer who opted out of auto-loaded migrations.
 */
abstract class IgnoredMigrationsTestCase extends NoManualMigrationsTestCase
{
    protected function setUp(): void
    {
        WhatsAppServiceProvider::ignoreMigrations();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        WhatsAppServiceProvider::$runsMigrations = true;

        parent::tearDown();
    }
}
