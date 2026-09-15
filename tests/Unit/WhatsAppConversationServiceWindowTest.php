<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

beforeEach(function () {
    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);
});

function makeConversation(WhatsAppPhone $phone, ?Carbon $lastInboundAt): WhatsAppConversation
{
    return WhatsAppConversation::create([
        'whatsapp_phone_id' => $phone->id,
        'contact_phone' => '+5511999999999',
        'last_message_at' => now(),
        'last_inbound_message_at' => $lastInboundAt,
        'status' => WhatsAppConversation::STATUS_ACTIVE,
    ]);
}

it('is within the window right after the contact messages in', function () {
    $conversation = makeConversation($this->phone, now());

    expect($conversation->isWithin24HourWindow())->toBeTrue();
});

it('is still within the window just under 24 hours later', function () {
    $conversation = makeConversation($this->phone, now()->subHours(23)->subMinutes(59));

    expect($conversation->isWithin24HourWindow())->toBeTrue();
});

it('is outside the window once 24 hours have passed', function () {
    $conversation = makeConversation($this->phone, now()->subHours(24)->subMinute());

    expect($conversation->isWithin24HourWindow())->toBeFalse();
});

it('is outside the window when the contact has never messaged in', function () {
    $conversation = makeConversation($this->phone, null);

    expect($conversation->isWithin24HourWindow())->toBeFalse()
        ->and($conversation->serviceWindowExpiresAt())->toBeNull();
});

it('is not extended by outbound-only activity', function () {
    // last_message_at reflects a recent outbound send, but the contact
    // last wrote in 25 hours ago — the window must still read as closed.
    $conversation = makeConversation($this->phone, now()->subHours(25));
    $conversation->update(['last_message_at' => now()]);

    expect($conversation->isWithin24HourWindow())->toBeFalse();
});

it('reports when the window expires', function () {
    $lastInboundAt = now()->subHours(2);
    $conversation = makeConversation($this->phone, $lastInboundAt);

    $expected = $lastInboundAt->clone()->addHours(24);

    expect($conversation->serviceWindowExpiresAt()->diffInSeconds($expected, absolute: true))->toBeLessThan(1);
});
