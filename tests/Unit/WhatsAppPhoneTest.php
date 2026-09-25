<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

function phoneWithToken(?string $token): WhatsAppPhone
{
    return WhatsAppPhone::create([
        'key' => 'encrypted',
        'phone_id' => '123456789',
        'phone_number' => '+5511999999999',
        'business_account_id' => 'waba123',
        'access_token' => $token,
    ]);
}

function rawToken(WhatsAppPhone $phone): ?string
{
    return DB::table('whatsapp_phones')->where('id', $phone->id)->value('access_token');
}

it('creates a phone with default values', function () {
    $phone = WhatsAppPhone::create([
        'key' => 'support',
        'phone_id' => '123456789',
        'phone_number' => '+5511999999999',
        'business_account_id' => 'waba123',
    ]);

    expect($phone->processing_mode)->toBe('batch');
    expect($phone->batch_window_seconds)->toBe(3);
    expect($phone->batch_max_messages)->toBe(10);
    expect($phone->auto_download_media)->toBeTrue();
    expect($phone->transcription_enabled)->toBeFalse();
    expect($phone->is_active)->toBeTrue();
});

it('checks message type filtering', function () {
    $phone = WhatsAppPhone::create([
        'key' => 'restricted',
        'phone_id' => '123456789',
        'phone_number' => '+5511999999999',
        'business_account_id' => 'waba123',
        'allowed_message_types' => ['text', 'image'],
    ]);

    expect($phone->isMessageTypeAllowed('text'))->toBeTrue();
    expect($phone->isMessageTypeAllowed('image'))->toBeTrue();
    expect($phone->isMessageTypeAllowed('audio'))->toBeFalse();
    expect($phone->isMessageTypeAllowed('video'))->toBeFalse();
});

it('allows all types when wildcard is set', function () {
    $phone = WhatsAppPhone::create([
        'key' => 'all_types',
        'phone_id' => '123456789',
        'phone_number' => '+5511999999999',
        'business_account_id' => 'waba123',
        'allowed_message_types' => ['*'],
    ]);

    expect($phone->isMessageTypeAllowed('text'))->toBeTrue();
    expect($phone->isMessageTypeAllowed('audio'))->toBeTrue();
    expect($phone->isMessageTypeAllowed('video'))->toBeTrue();
    expect($phone->isMessageTypeAllowed('location'))->toBeTrue();
});

it('allows all types when null', function () {
    $phone = WhatsAppPhone::create([
        'key' => 'null_types',
        'phone_id' => '123456789',
        'phone_number' => '+5511999999999',
        'business_account_id' => 'waba123',
        'allowed_message_types' => null,
    ]);

    expect($phone->isMessageTypeAllowed('text'))->toBeTrue();
    expect($phone->isMessageTypeAllowed('anything'))->toBeTrue();
});

it('identifies processing mode', function () {
    $batchPhone = WhatsAppPhone::create([
        'key' => 'batch',
        'phone_id' => '123456789',
        'phone_number' => '+5511999999999',
        'business_account_id' => 'waba123',
        'processing_mode' => 'batch',
    ]);

    $immediatePhone = WhatsAppPhone::create([
        'key' => 'immediate',
        'phone_id' => '987654321',
        'phone_number' => '+5511888888888',
        'business_account_id' => 'waba456',
        'processing_mode' => 'immediate',
    ]);

    expect($batchPhone->isBatchMode())->toBeTrue();
    expect($batchPhone->isImmediateMode())->toBeFalse();
    expect($immediatePhone->isBatchMode())->toBeFalse();
    expect($immediatePhone->isImmediateMode())->toBeTrue();
});

it('falls back to global access token', function () {
    config(['whatsapp.access_token' => 'global_token']);

    $phone = WhatsAppPhone::create([
        'key' => 'no_token',
        'phone_id' => '123456789',
        'phone_number' => '+5511999999999',
        'business_account_id' => 'waba123',
        'access_token' => null,
    ]);

    expect($phone->access_token)->toBe('global_token');
});

it('uses phone-specific token when set', function () {
    config(['whatsapp.access_token' => 'global_token']);

    $phone = WhatsAppPhone::create([
        'key' => 'has_token',
        'phone_id' => '123456789',
        'phone_number' => '+5511999999999',
        'business_account_id' => 'waba123',
        'access_token' => 'phone_specific_token',
    ]);

    expect($phone->access_token)->toBe('phone_specific_token');
});

describe('access token encryption at rest', function () {
    it('stores the token encrypted and reads it back decrypted', function () {
        $phone = phoneWithToken('EAAB.secret');

        expect(rawToken($phone))->not->toBe('EAAB.secret')
            ->and(Crypt::decryptString(rawToken($phone)))->toBe('EAAB.secret')
            ->and($phone->fresh()->access_token)->toBe('EAAB.secret');
    });

    it('keeps a null token null so the app-wide token still applies', function () {
        config(['whatsapp.access_token' => 'global_token']);

        $phone = phoneWithToken(null);

        expect(rawToken($phone))->toBeNull()
            ->and($phone->fresh()->access_token)->toBe('global_token');
    });

    it('reads a legacy plaintext token without throwing', function () {
        $phone = phoneWithToken(null);
        DB::table('whatsapp_phones')->where('id', $phone->id)->update(['access_token' => 'EAAB.legacy']);

        expect($phone->fresh()->access_token)->toBe('EAAB.legacy');
    });

    it('re-encrypts a legacy plaintext token on the next save', function () {
        $phone = phoneWithToken(null);
        DB::table('whatsapp_phones')->where('id', $phone->id)->update(['access_token' => 'EAAB.legacy']);

        $phone = $phone->fresh();
        $phone->update(['display_name' => 'Support']);

        expect(rawToken($phone))->not->toBe('EAAB.legacy')
            ->and(Crypt::decryptString(rawToken($phone)))->toBe('EAAB.legacy')
            ->and($phone->fresh()->access_token)->toBe('EAAB.legacy');
    });

    it('leaves an already-encrypted token untouched on save', function () {
        $phone = phoneWithToken('EAAB.secret');
        $before = rawToken($phone);

        $phone->fresh()->update(['display_name' => 'Support']);

        expect(rawToken($phone))->toBe($before);
    });

    it('refuses a token sealed with another app key instead of treating it as plaintext', function () {
        $phone = phoneWithToken(null);
        $foreign = (new Encrypter(str_repeat('b', 32), 'AES-256-CBC'))->encrypt('EAAB.secret', false);
        DB::table('whatsapp_phones')->where('id', $phone->id)->update(['access_token' => $foreign]);

        expect(fn () => $phone->fresh()->access_token)->toThrow(DecryptException::class)
            ->and(fn () => $phone->fresh()->update(['display_name' => 'Support']))->toThrow(DecryptException::class)
            ->and(rawToken($phone))->toBe($foreign);
    });
});
