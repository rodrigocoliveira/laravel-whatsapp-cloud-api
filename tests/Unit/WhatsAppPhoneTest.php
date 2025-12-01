<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

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
