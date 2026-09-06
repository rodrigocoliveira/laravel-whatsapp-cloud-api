<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Multek\LaravelWhatsAppCloud\Tests\Support\IgnoredMigrationsTestCase;

uses(IgnoredMigrationsTestCase::class);

it('does not auto-load migrations once the consumer calls ignoreMigrations()', function () {
    expect(Schema::hasTable('whatsapp_phones'))->toBeFalse();
});
