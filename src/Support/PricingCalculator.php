<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Support;

/**
 * Maps Meta's billing classification to a monetary rate.
 *
 * Meta never sends the amount charged on webhooks, only the billing
 * category. This class resolves that category against the rate card in
 * `config/whatsapp.php` (`pricing.rates`), keyed by recipient dial code.
 */
class PricingCalculator
{
    public const DEFAULT_KEY = 'default';

    /**
     * @param  array{currency?: string, rates?: array<string, array<string, float|int>>}  $config
     */
    public function __construct(protected array $config) {}

    public function currency(): string
    {
        return (string) ($this->config['currency'] ?? 'USD');
    }

    /**
     * Resolve the per-message rate for a recipient and billing category.
     *
     * The longest dial-code prefix matching the recipient wins; the `default`
     * rate card is used when no prefix matches. Returns null when no rate is
     * configured so callers can distinguish "free" (0.0) from "unknown".
     */
    public function rateFor(string $recipient, ?string $category): ?float
    {
        if ($category === null || $category === '') {
            return null;
        }

        $rates = $this->rateCardFor($recipient);
        if ($rates === null || ! array_key_exists($category, $rates)) {
            return null;
        }

        return (float) $rates[$category];
    }

    /**
     * @return array<string, float|int>|null
     */
    protected function rateCardFor(string $recipient): ?array
    {
        $digits = PhoneNumberHelper::toDigits($recipient);
        $rates = $this->config['rates'] ?? [];

        $bestPrefix = null;
        foreach (array_keys($rates) as $prefix) {
            $prefix = (string) $prefix;
            if ($prefix === self::DEFAULT_KEY || ! str_starts_with($digits, $prefix)) {
                continue;
            }
            if ($bestPrefix === null || strlen($prefix) > strlen($bestPrefix)) {
                $bestPrefix = $prefix;
            }
        }

        if ($bestPrefix !== null) {
            return $rates[$bestPrefix];
        }

        return $rates[self::DEFAULT_KEY] ?? null;
    }
}
