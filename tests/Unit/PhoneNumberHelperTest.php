<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Support\PhoneNumberHelper;

describe('PhoneNumberHelper', function () {
    describe('normalize', function () {
        it('adds + prefix to digits-only phone numbers', function () {
            expect(PhoneNumberHelper::normalize('5511999999999'))
                ->toBe('+5511999999999');
        });

        it('keeps + prefix if already present', function () {
            expect(PhoneNumberHelper::normalize('+5511999999999'))
                ->toBe('+5511999999999');
        });

        it('removes spaces and special characters', function () {
            expect(PhoneNumberHelper::normalize('+55 11 99999-9999'))
                ->toBe('+5511999999999');
        });

        it('handles phone numbers with parentheses', function () {
            expect(PhoneNumberHelper::normalize('+55 (11) 99999-9999'))
                ->toBe('+5511999999999');
        });

        it('returns original if empty digits', function () {
            expect(PhoneNumberHelper::normalize(''))
                ->toBe('');
        });
    });

    describe('toDigits', function () {
        it('removes + prefix', function () {
            expect(PhoneNumberHelper::toDigits('+5511999999999'))
                ->toBe('5511999999999');
        });

        it('removes all non-numeric characters', function () {
            expect(PhoneNumberHelper::toDigits('+55 (11) 99999-9999'))
                ->toBe('5511999999999');
        });

        it('handles digits-only input', function () {
            expect(PhoneNumberHelper::toDigits('5511999999999'))
                ->toBe('5511999999999');
        });
    });

    describe('equals', function () {
        it('returns true for same numbers with different formats', function () {
            expect(PhoneNumberHelper::equals('+5511999999999', '5511999999999'))
                ->toBeTrue();
        });

        it('returns true for same numbers with spaces and dashes', function () {
            expect(PhoneNumberHelper::equals('+55 11 99999-9999', '5511999999999'))
                ->toBeTrue();
        });

        it('returns false for different numbers', function () {
            expect(PhoneNumberHelper::equals('+5511999999999', '+5511888888888'))
                ->toBeFalse();
        });
    });
});
