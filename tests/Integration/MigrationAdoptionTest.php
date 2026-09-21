<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Multek\LaravelWhatsAppCloud\Tests\Support\MigrationScenarioTestCase;

uses(MigrationScenarioTestCase::class);

// v1.3.x shipped eleven migrations; 000012 arrived in a later release, so a consumer
// who published back then has copies of the first eleven and none of the twelfth.
const PUBLISHED_BEFORE_UPGRADE = 11;

it('keeps running the published copies and only adds the vendor migrations that were never published', function () {
    $this->migrateAsPublishedConsumer(PUBLISHED_BEFORE_UPGRADE);
    $this->upgradePackage();

    Artisan::call('migrate');

    $ran = $this->ranMigrations();

    expect($ran)->toContain('2026_02_06_235401_create_whatsapp_phones_table')
        ->and($ran)->not->toContain('2024_01_01_000001_create_whatsapp_phones_table')
        ->and($ran)->toContain('2024_01_01_000012_add_last_inbound_message_at_to_whatsapp_conversations')
        ->and(Schema::hasColumn('whatsapp_conversations', 'last_inbound_message_at'))->toBeTrue();
});

it('adopts the published migration history once the consumer deletes the copies', function () {
    $this->migrateAsPublishedConsumer(PUBLISHED_BEFORE_UPGRADE);
    $this->deletePublishedMigrations();
    $this->upgradePackage();

    Artisan::call('migrate');

    $ran = $this->ranMigrations();

    expect($ran)->toContain('2024_01_01_000001_create_whatsapp_phones_table')
        ->and($ran)->not->toContain('2026_02_06_235401_create_whatsapp_phones_table')
        ->and($ran)->toContain('2024_01_01_000012_add_last_inbound_message_at_to_whatsapp_conversations')
        ->and($ran)->toHaveCount(12)
        ->and(Schema::hasTable('whatsapp_phones'))->toBeTrue();

    Artisan::call('migrate');

    expect($this->ranMigrations())->toHaveCount(12);
});

it('refuses to migrate a fresh database while unpublished vendor migrations would run before the published copies', function () {
    $this->publishVendorMigrations(PUBLISHED_BEFORE_UPGRADE);
    $this->upgradePackage();

    expect(fn () => Artisan::call('migrate'))
        ->toThrow(RuntimeException::class, 'published copies');

    expect(Schema::hasTable('whatsapp_phones'))->toBeFalse();
});
