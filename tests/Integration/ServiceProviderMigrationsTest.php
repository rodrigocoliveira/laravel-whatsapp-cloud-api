<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Multek\LaravelWhatsAppCloud\Tests\Support\NoManualMigrationsTestCase;

uses(NoManualMigrationsTestCase::class);

it('runs the package migrations automatically, without the consumer publishing them', function () {
    expect(Schema::hasTable('whatsapp_phones'))->toBeTrue();
    expect(Schema::hasTable('whatsapp_messages'))->toBeTrue();
});
