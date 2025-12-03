<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Multek\LaravelWhatsAppCloud\Events\MessageDelivered;
use Multek\LaravelWhatsAppCloud\Events\MessageRead;
use Multek\LaravelWhatsAppCloud\Events\MessageSent;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
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

it('handles duplicate message race condition atomically', function () {
    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $payload = $this->getTextMessagePayload();
    $signature = $this->generateSignature($payload);

    // Simulate concurrent requests by sending same payload with same message_id
    // The firstOrCreate should ensure only one record is created
    $responses = [];
    for ($i = 0; $i < 3; $i++) {
        $responses[] = $this->postJson('/webhooks/whatsapp', $payload, [
            'X-Hub-Signature-256' => $signature,
        ]);
    }

    // All requests should succeed
    foreach ($responses as $response) {
        $response->assertOk();
    }

    // But only one message should be created
    $this->assertDatabaseCount('whatsapp_messages', 1);
});

it('ignores duplicate status webhooks', function () {
    Event::fake();

    $phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    // Create an outbound message
    $message = WhatsAppMessage::create([
        'whatsapp_phone_id' => $phone->id,
        'message_id' => 'wamid.test123',
        'direction' => 'outbound',
        'type' => 'text',
        'from' => '+15551234567',
        'to' => '5511999999999',
        'content' => ['text' => ['body' => 'Test']],
        'text_body' => 'Test',
        'status' => 'sent',
    ]);

    // First status webhook - should fire event
    $payload = $this->getStatusWebhookPayload('wamid.test123', 'delivered');
    $signature = $this->generateSignature($payload);

    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();

    Event::assertDispatched(MessageDelivered::class, 1);

    // Duplicate status webhook - should NOT fire event again
    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();

    // Event should still only have been dispatched once
    Event::assertDispatched(MessageDelivered::class, 1);
});

it('fires events for each unique status change', function () {
    Event::fake();

    $phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    // Create an outbound message
    $message = WhatsAppMessage::create([
        'whatsapp_phone_id' => $phone->id,
        'message_id' => 'wamid.test456',
        'direction' => 'outbound',
        'type' => 'text',
        'from' => '+15551234567',
        'to' => '5511999999999',
        'content' => ['text' => ['body' => 'Test']],
        'text_body' => 'Test',
        'status' => 'sent',
    ]);

    // Send sent status
    $payload = $this->getStatusWebhookPayload('wamid.test456', 'sent');
    $signature = $this->generateSignature($payload);
    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();

    // Send delivered status
    $payload = $this->getStatusWebhookPayload('wamid.test456', 'delivered');
    $signature = $this->generateSignature($payload);
    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();

    // Send read status
    $payload = $this->getStatusWebhookPayload('wamid.test456', 'read');
    $signature = $this->generateSignature($payload);
    $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $signature,
    ])->assertOk();

    // Each unique status should fire exactly once
    Event::assertDispatched(MessageSent::class, 1);
    Event::assertDispatched(MessageDelivered::class, 1);
    Event::assertDispatched(MessageRead::class, 1);

    // Verify database state
    $message->refresh();
    expect($message->sent_at)->not->toBeNull();
    expect($message->delivered_at)->not->toBeNull();
    expect($message->read_at)->not->toBeNull();
});
