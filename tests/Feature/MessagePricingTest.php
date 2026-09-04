<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

function createOutboundMessage(string $messageId, string $to = '+5511999999999'): WhatsAppMessage
{
    $phone = WhatsAppPhone::firstOrCreate(['key' => 'test'], [
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    return WhatsAppMessage::create([
        'whatsapp_phone_id' => $phone->id,
        'message_id' => $messageId,
        'direction' => 'outbound',
        'type' => 'text',
        'from' => '+15551234567',
        'to' => $to,
        'content' => ['text' => ['body' => 'Test']],
        'text_body' => 'Test',
        'status' => 'sent',
    ]);
}

function postStatus($test, string $messageId, string $status, array $overrides = []): void
{
    // Helpers are protected on the TestCase, so run inside its scope.
    (function () use ($messageId, $status, $overrides) {
        $payload = $this->getStatusWebhookPayload($messageId, $status, $overrides);

        $this->postJson('/webhooks/whatsapp', $payload, [
            'X-Hub-Signature-256' => $this->generateSignature($payload),
        ])->assertOk();
    })->call($test);
}

it('stores pricing and conversation data from a sent status webhook', function () {
    $message = createOutboundMessage('wamid.pricing1');

    postStatus($this, 'wamid.pricing1', 'sent', [
        'conversation' => [
            'id' => 'conv_abc123',
            'origin' => ['type' => 'marketing'],
            'expiration_timestamp' => '1756986400',
        ],
        'pricing' => [
            'billable' => true,
            'pricing_model' => 'PMP',
            'category' => 'marketing',
            'type' => 'regular',
        ],
    ]);

    $message->refresh();

    expect($message->pricing_billable)->toBeTrue()
        ->and($message->pricing_model)->toBe('PMP')
        ->and($message->pricing_category)->toBe('marketing')
        ->and($message->pricing_type)->toBe('regular')
        ->and($message->meta_conversation_id)->toBe('conv_abc123')
        ->and($message->conversation_origin)->toBe('marketing')
        ->and($message->conversation_expires_at?->timestamp)->toBe(1756986400);
});

it('estimates cost from the configured rate card when the message is billable', function () {
    config()->set('whatsapp.pricing.rates', [
        '55' => ['marketing' => 0.0625, 'utility' => 0.008],
    ]);

    $message = createOutboundMessage('wamid.cost1', '+5511999999999');
    $message->update([
        'pricing_billable' => true,
        'pricing_category' => 'marketing',
        'pricing_type' => 'regular',
    ]);

    expect($message->isBillable())->toBeTrue()
        ->and($message->estimatedCost())->toBe(0.0625);
});

it('estimates zero cost for non-billable messages', function () {
    config()->set('whatsapp.pricing.rates', [
        '55' => ['service' => 0.0, 'marketing' => 0.0625],
    ]);

    $message = createOutboundMessage('wamid.cost2', '+5511999999999');
    $message->update([
        'pricing_billable' => false,
        'pricing_category' => 'service',
        'pricing_type' => 'free_customer_service',
    ]);

    expect($message->isBillable())->toBeFalse()
        ->and($message->estimatedCost())->toBe(0.0);
});

it('returns null cost when pricing information has not arrived yet', function () {
    $message = createOutboundMessage('wamid.cost3', '+5511999999999');

    expect($message->isBillable())->toBeNull()
        ->and($message->estimatedCost())->toBeNull();
});

it('returns null cost when billable but no rate is configured', function () {
    config()->set('whatsapp.pricing.rates', []);

    $message = createOutboundMessage('wamid.cost4', '+5511999999999');
    $message->update(['pricing_billable' => true, 'pricing_category' => 'marketing']);

    expect($message->estimatedCost())->toBeNull();
});

it('captures pricing from a later status when the sent webhook was missed', function () {
    $message = createOutboundMessage('wamid.pricing2');

    postStatus($this, 'wamid.pricing2', 'delivered', [
        'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'utility', 'type' => 'regular'],
    ]);

    $message->refresh();

    expect($message->pricing_billable)->toBeTrue()
        ->and($message->pricing_category)->toBe('utility');
});

it('keeps stored pricing when a later status webhook omits it', function () {
    $message = createOutboundMessage('wamid.pricing3');

    postStatus($this, 'wamid.pricing3', 'sent', [
        'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'marketing', 'type' => 'regular'],
    ]);
    postStatus($this, 'wamid.pricing3', 'read');

    $message->refresh();

    expect($message->read_at)->not->toBeNull()
        ->and($message->pricing_billable)->toBeTrue()
        ->and($message->pricing_category)->toBe('marketing');
});
