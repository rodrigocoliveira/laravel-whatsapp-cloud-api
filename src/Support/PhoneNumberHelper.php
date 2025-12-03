<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Support;

class PhoneNumberHelper
{
    /**
     * Normalize a phone number to E.164 format (+XXXXXXXXXXX).
     *
     * This ensures consistent phone number storage across the package.
     * Facebook webhooks send numbers without "+", but we standardize
     * to E.164 format for consistency and easier lookups.
     *
     * @param  string  $phone  The phone number in any format
     * @return string The normalized phone number with "+" prefix
     *
     * @example
     * PhoneNumberHelper::normalize('5511999999999')   // Returns '+5511999999999'
     * PhoneNumberHelper::normalize('+5511999999999')  // Returns '+5511999999999'
     * PhoneNumberHelper::normalize('55 11 99999-9999') // Returns '+5511999999999'
     */
    public static function normalize(string $phone): string
    {
        // Remove all non-numeric characters
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? $phone;

        // Add + prefix if not empty
        if ($digits === '') {
            return $phone;
        }

        return '+' . $digits;
    }

    /**
     * Get only the digits from a phone number (for API calls).
     *
     * Meta's WhatsApp API expects phone numbers without the "+" prefix.
     *
     * @param  string  $phone  The phone number in any format
     * @return string The phone number with only digits
     *
     * @example
     * PhoneNumberHelper::toDigits('+5511999999999') // Returns '5511999999999'
     * PhoneNumberHelper::toDigits('5511999999999')  // Returns '5511999999999'
     */
    public static function toDigits(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? $phone;
    }

    /**
     * Compare two phone numbers for equality (ignoring formatting).
     *
     * @param  string  $phone1  First phone number
     * @param  string  $phone2  Second phone number
     * @return bool True if the phone numbers are the same
     *
     * @example
     * PhoneNumberHelper::equals('+5511999999999', '5511999999999') // Returns true
     * PhoneNumberHelper::equals('55 11 99999-9999', '+5511999999999') // Returns true
     */
    public static function equals(string $phone1, string $phone2): bool
    {
        return self::toDigits($phone1) === self::toDigits($phone2);
    }
}
