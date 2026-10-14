<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Support\PhoneNumberHelper;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200)]);

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->receive = function (string $waId) {
        $payload = $this->getTextMessagePayload($waId, 'Oi');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.'.uniqid();
        $this->postJson('/webhooks/whatsapp', $payload, ['X-Hub-Signature-256' => $this->generateSignature($payload)])->assertOk();
    };
});

it('lists BR mobile variants only', function () {
    expect(PhoneNumberHelper::variants('5582991111097'))->toBe(['+5582991111097', '+558291111097'])
        ->and(PhoneNumberHelper::variants('+558291111097'))->toBe(['+558291111097', '+5582991111097'])
        ->and(PhoneNumberHelper::variants('558233334444'))->toBe(['+558233334444'])
        ->and(PhoneNumberHelper::variants('15551234567'))->toBe(['+15551234567']);
});

it('lands inbound wa_id without 9 on the conversation created by an outbound with 9', function () {
    $sent = app(WhatsAppManager::class)->phone('test')->sendText('5582991111097', 'Cotação');
    ($this->receive)('558291111097');

    $conversation = WhatsAppConversation::sole();
    expect($conversation->id)->toBe($sent->whatsapp_conversation_id)
        ->and($conversation->contact_phone)->toBe('+558291111097')
        ->and(WhatsAppMessage::count())->toBe(2);
});

it('lands outbound with 9 on the conversation created by an inbound wa_id', function () {
    ($this->receive)('558291111097');
    $sent = app(WhatsAppManager::class)->phone('test')->sendText('5582991111097', 'Resposta');

    expect(WhatsAppConversation::sole()->id)->toBe($sent->whatsapp_conversation_id)
        ->and(WhatsAppConversation::sole()->contact_phone)->toBe('+558291111097');
});

it('keeps BR landlines with and without 9 as separate conversations', function () {
    app(WhatsAppManager::class)->phone('test')->sendText('5582933334444', 'A');
    ($this->receive)('558233334444');

    expect(WhatsAppConversation::count())->toBe(2);
});

it('merges existing duplicate pairs into the inbound wa_id row', function () {
    $make = fn (string $contact, array $attrs = []) => WhatsAppConversation::create(array_merge([
        'whatsapp_phone_id' => $this->phone->id,
        'contact_phone' => $contact,
        'status' => WhatsAppConversation::STATUS_ACTIVE,
        'last_message_at' => now()->subDay(),
    ], $attrs));

    $withNine = $make('+5582991111097', ['unread_count' => 1, 'last_message_at' => now(), 'contact_name' => 'Fornecedor']);
    $withoutNine = $make('+558291111097', ['unread_count' => 2]);
    $landline = $make('+558233334444');
    $make('+5582933334444');

    $msg = WhatsAppMessage::create([
        'whatsapp_phone_id' => $this->phone->id,
        'whatsapp_conversation_id' => $withNine->id,
        'message_id' => 'wamid.x',
        'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
        'type' => 'text',
        'from' => '+15551234567',
        'to' => '+5582991111097',
        'status' => 'sent',
    ]);

    $this->artisan('whatsapp:merge-br-duplicate-conversations', ['--dry-run' => true])->assertSuccessful();
    expect(WhatsAppConversation::count())->toBe(4);

    $this->artisan('whatsapp:merge-br-duplicate-conversations')->assertSuccessful();

    $kept = $withoutNine->fresh();
    expect(WhatsAppConversation::find($withNine->id))->toBeNull()
        ->and($landline->fresh())->not->toBeNull()
        ->and(WhatsAppConversation::count())->toBe(3)
        ->and($msg->fresh()->whatsapp_conversation_id)->toBe($kept->id)
        ->and($kept->unread_count)->toBe(3)
        ->and($kept->contact_name)->toBe('Fornecedor')
        ->and($kept->last_message_at->isToday())->toBeTrue();
});

it('resolves to the exact row without renaming while both variants still exist', function () {
    foreach (['+5582991111097', '+558291111097'] as $contact) {
        WhatsAppConversation::create(['whatsapp_phone_id' => $this->phone->id, 'contact_phone' => $contact, 'status' => WhatsAppConversation::STATUS_ACTIVE, 'last_message_at' => now()]);
    }

    ($this->receive)('558291111097');
    $sent = app(WhatsAppManager::class)->phone('test')->sendText('5582991111097', 'Oi');

    expect(WhatsAppConversation::pluck('contact_phone')->sort()->values()->all())->toBe(['+558291111097', '+5582991111097'])
        ->and(WhatsAppMessage::where('direction', 'inbound')->sole()->conversation->contact_phone)->toBe('+558291111097')
        ->and($sent->conversation->contact_phone)->toBe('+5582991111097');
});

it('scopes conversations to a contact under either BR mobile form only', function () {
    foreach (['+558291111097', '+5582933334444'] as $contact) {
        WhatsAppConversation::create(['whatsapp_phone_id' => $this->phone->id, 'contact_phone' => $contact, 'status' => WhatsAppConversation::STATUS_ACTIVE, 'last_message_at' => now()]);
    }

    expect(WhatsAppConversation::forContact('5582991111097')->pluck('contact_phone')->all())->toBe(['+558291111097'])
        ->and(WhatsAppConversation::forContact('558233334444')->exists())->toBeFalse();
});
