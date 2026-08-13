<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

it('caches the phone as a plain attribute array instead of the Eloquent model', function () {
    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $payload = $this->getTextMessagePayload();
    $signature = $this->generateSignature($payload);

    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();

    $cached = Cache::get('whatsapp:phone:test_phone_id');

    expect($cached)->toBeArray();
    expect($cached['phone_id'])->toBe('test_phone_id');
});

it('processes a message webhook from cached attributes without querying the phones table', function () {
    // Isolate the webhook processor — queued jobs re-fetch models by design
    Queue::fake();

    $phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    // Simulate a cache hit in the attribute-array format
    Cache::put('whatsapp:phone:test_phone_id', $phone->getAttributes(), 300);

    $phoneQueries = [];
    DB::listen(function ($query) use (&$phoneQueries) {
        if (str_contains($query->sql, 'whatsapp_phones')) {
            $phoneQueries[] = $query->sql;
        }
    });

    $payload = $this->getTextMessagePayload('5511999999999', 'Hello from cache');
    $signature = $this->generateSignature($payload);

    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();

    $this->assertDatabaseHas('whatsapp_messages', [
        'whatsapp_phone_id' => $phone->id,
        'type' => 'text',
        'text_body' => 'Hello from cache',
    ]);

    expect($phoneQueries)->toBeEmpty();
});

it('processes a status webhook from cached attributes', function () {
    $phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $message = WhatsAppMessage::create([
        'whatsapp_phone_id' => $phone->id,
        'message_id' => 'wamid.cached_status',
        'direction' => 'outbound',
        'type' => 'text',
        'from' => '+15551234567',
        'to' => '5511999999999',
        'content' => ['text' => ['body' => 'Test']],
        'text_body' => 'Test',
        'status' => 'sent',
    ]);

    // Simulate a cache hit in the attribute-array format
    Cache::put('whatsapp:phone:test_phone_id', $phone->getAttributes(), 300);

    $payload = $this->getStatusWebhookPayload('wamid.cached_status', 'delivered');
    $signature = $this->generateSignature($payload);

    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();

    $message->refresh();
    expect($message->delivery_status)->toBe('delivered');
    expect($message->delivered_at)->not->toBeNull();
});
