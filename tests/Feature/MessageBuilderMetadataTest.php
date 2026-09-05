<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Events\MessageSent;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Http::fake([
        '*' => Http::response(['messages' => [['id' => 'wamid.metadata']]], 200),
    ]);

    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->manager = app(WhatsAppManager::class);
});

it('stamps the outbound message with metadata before send() fires MessageSent', function () {
    $metadataSeenByListener = null;

    Event::listen(MessageSent::class, function (MessageSent $event) use (&$metadataSeenByListener) {
        $metadataSeenByListener = $event->message->metadata;
    });

    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->text('Hello')
        ->metadata(['sent_by_admin_id' => 42, 'sent_by_name' => 'Ana'])
        ->send();

    expect($message->metadata)->toBe(['sent_by_admin_id' => 42, 'sent_by_name' => 'Ana'])
        ->and($metadataSeenByListener)->toBe(['sent_by_admin_id' => 42, 'sent_by_name' => 'Ana']);
});

it('stamps the pending message with metadata when queued', function () {
    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->text('Hello')
        ->metadata(['sent_by_admin_id' => 7])
        ->queue();

    expect($message->metadata)->toBe(['sent_by_admin_id' => 7]);
});
