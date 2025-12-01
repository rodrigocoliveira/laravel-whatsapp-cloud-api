<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

it('verifies webhook subscription', function () {
    $response = $this->get('/webhooks/whatsapp?'.http_build_query([
        'hub_mode' => 'subscribe',
        'hub_verify_token' => config('whatsapp.webhook.verify_token'),
        'hub_challenge' => 'test_challenge_123',
    ]));

    $response->assertOk();
    $response->assertSee('test_challenge_123');
});

it('rejects invalid verify token', function () {
    $response = $this->get('/webhooks/whatsapp?'.http_build_query([
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'wrong_token',
        'hub_challenge' => 'test_challenge',
    ]));

    $response->assertStatus(500);
});

it('rejects invalid hub mode', function () {
    $response = $this->get('/webhooks/whatsapp?'.http_build_query([
        'hub_mode' => 'invalid',
        'hub_verify_token' => config('whatsapp.webhook.verify_token'),
        'hub_challenge' => 'test_challenge',
    ]));

    $response->assertStatus(500);
});

it('processes incoming text message', function () {
    // Create a phone for the webhook to find
    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $payload = $this->getTextMessagePayload('5511999999999', 'Hello World');
    $signature = $this->generateSignature($payload);

    $response = $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ]);

    $response->assertOk();
    $response->assertSee('EVENT_RECEIVED');

    $this->assertDatabaseHas('whatsapp_messages', [
        'type' => 'text',
        'direction' => 'inbound',
        'from' => '5511999999999',
        'text_body' => 'Hello World',
    ]);

    $this->assertDatabaseHas('whatsapp_conversations', [
        'contact_phone' => '5511999999999',
        'contact_name' => 'Test User',
    ]);
});

it('rejects webhook with invalid signature', function () {
    $payload = $this->getTextMessagePayload();

    $response = $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => 'sha256=invalid_signature',
    ]);

    $response->assertStatus(500);
});

it('ignores duplicate messages', function () {
    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $payload = $this->getTextMessagePayload();
    $signature = $this->generateSignature($payload);

    // Send first message
    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ]);

    // Send duplicate
    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ]);

    // Should only have one message
    $this->assertDatabaseCount('whatsapp_messages', 1);
});
