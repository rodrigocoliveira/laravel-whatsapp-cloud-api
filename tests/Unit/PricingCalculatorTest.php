<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Support\PricingCalculator;

function makeCalculator(array $rates, string $currency = 'USD'): PricingCalculator
{
    return new PricingCalculator(['currency' => $currency, 'rates' => $rates]);
}

it('looks up the rate by recipient dial code and category', function () {
    $calculator = makeCalculator([
        '55' => ['marketing' => 0.0625, 'utility' => 0.008],
    ]);

    expect($calculator->rateFor('+5511999999999', 'marketing'))->toBe(0.0625)
        ->and($calculator->rateFor('5511999999999', 'utility'))->toBe(0.008);
});

it('prefers the longest matching dial code prefix', function () {
    $calculator = makeCalculator([
        '1' => ['marketing' => 0.025],
        '1876' => ['marketing' => 0.09],
    ]);

    expect($calculator->rateFor('+18765551234', 'marketing'))->toBe(0.09)
        ->and($calculator->rateFor('+12125551234', 'marketing'))->toBe(0.025);
});

it('falls back to the default rate card when no prefix matches', function () {
    $calculator = makeCalculator([
        '55' => ['marketing' => 0.0625],
        'default' => ['marketing' => 0.05],
    ]);

    expect($calculator->rateFor('+4915112345678', 'marketing'))->toBe(0.05);
});

it('returns null when no rate is configured for the category', function () {
    $calculator = makeCalculator([
        '55' => ['marketing' => 0.0625],
    ]);

    expect($calculator->rateFor('+5511999999999', 'authentication'))->toBeNull()
        ->and($calculator->rateFor('+4915112345678', 'marketing'))->toBeNull()
        ->and($calculator->rateFor('+5511999999999', null))->toBeNull();
});

it('exposes the configured currency', function () {
    expect(makeCalculator([], 'BRL')->currency())->toBe('BRL');
});
