<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSendMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Http::fake([
        '*' => Http::response(['messages' => [['id' => 'wamid.reaction']]], 200),
    ]);

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->manager = app(WhatsAppManager::class);

    $this->conversation = WhatsAppConversation::create([
        'whatsapp_phone_id' => $this->phone->id,
        'contact_phone' => '+5511999999999',
        'last_message_at' => now(),
        'status' => WhatsAppConversation::STATUS_ACTIVE,
    ]);

    $this->storeMessage = function (string $messageId, string $direction, string $from, string $to): WhatsAppMessage {
        return WhatsAppMessage::create([
            'whatsapp_phone_id' => $this->phone->id,
            'whatsapp_conversation_id' => $this->conversation->id,
            'message_id' => $messageId,
            'direction' => $direction,
            'type' => 'text',
            'from' => $from,
            'to' => $to,
            'content' => ['body' => 'Hi'],
            'text_body' => 'Hi',
            'status' => WhatsAppMessage::STATUS_PROCESSED,
        ]);
    };
});

it('reacts to an inbound message by resolving the recipient from its sender', function () {
    ($this->storeMessage)('wamid.inbound', WhatsAppMessage::DIRECTION_INBOUND, '+5511999999999', '+15551234567');

    $reaction = $this->manager->phone('test')->sendReaction('wamid.inbound', '👍');

    expect($reaction->type)->toBe('reaction')
        ->and($reaction->to)->toBe('+5511999999999')
        ->and($reaction->whatsapp_conversation_id)->toBe($this->conversation->id)
        ->and($reaction->content)->toBe(['message_id' => 'wamid.inbound', 'emoji' => '👍'])
        ->and(WhatsAppConversation::count())->toBe(1);
});

it('sends the reaction to the contact rather than the business phone', function () {
    ($this->storeMessage)('wamid.inbound', WhatsAppMessage::DIRECTION_INBOUND, '+5511999999999', '+15551234567');

    $this->manager->phone('test')->sendReaction('wamid.inbound', '👍');

    Http::assertSent(fn (Request $request) => $request['to'] === '5511999999999'
        && $request['type'] === 'reaction'
        && $request['reaction'] === ['message_id' => 'wamid.inbound', 'emoji' => '👍']);
});

it('reacts to an outbound message by resolving the recipient from its destination', function () {
    ($this->storeMessage)('wamid.outbound', WhatsAppMessage::DIRECTION_OUTBOUND, '+15551234567', '+5511999999999');

    $reaction = $this->manager->phone('test')->sendReaction('wamid.outbound', '❤️');

    expect($reaction->to)->toBe('+5511999999999')
        ->and($reaction->whatsapp_conversation_id)->toBe($this->conversation->id);

    Http::assertSent(fn (Request $request) => $request['to'] === '5511999999999');
});

it('accepts an explicit recipient when the reacted message is not stored locally', function () {
    $reaction = $this->manager->phone('test')->sendReaction('wamid.unknown', '👍', '5511777777777');

    $conversation = WhatsAppConversation::where('contact_phone', '+5511777777777')->first();

    expect($reaction->to)->toBe('+5511777777777')
        ->and($conversation)->not->toBeNull()
        ->and($reaction->whatsapp_conversation_id)->toBe($conversation->id);

    Http::assertSent(fn (Request $request) => $request['to'] === '5511777777777');
});

it('refuses to react to an unknown message without an explicit recipient', function () {
    expect(fn () => $this->manager->phone('test')->sendReaction('wamid.unknown', '👍'))
        ->toThrow(InvalidArgumentException::class, 'wamid.unknown');

    expect(WhatsAppMessage::count())->toBe(0)
        ->and(WhatsAppConversation::count())->toBe(1);

    Http::assertNothingSent();
});

it('removes a reaction by sending an empty emoji to the original sender', function () {
    ($this->storeMessage)('wamid.inbound', WhatsAppMessage::DIRECTION_INBOUND, '+5511999999999', '+15551234567');

    $reaction = $this->manager->phone('test')->removeReaction('wamid.inbound');

    expect($reaction->to)->toBe('+5511999999999')
        ->and($reaction->content)->toBe(['message_id' => 'wamid.inbound', 'emoji' => '']);

    Http::assertSent(fn (Request $request) => $request['to'] === '5511999999999'
        && $request['reaction']['emoji'] === '');
});

it('sends a queued reaction to the contact when the job runs', function () {
    Queue::fake();

    $pending = $this->manager->phone('test')->to('5511999999999')->reaction('wamid.inbound', '👍')->queue();

    (new WhatsAppSendMessage($pending))->handle();

    expect($pending->fresh()->delivery_status)->toBe(WhatsAppMessage::DELIVERY_STATUS_SENT)
        ->and($pending->fresh()->message_id)->toBe('wamid.reaction');

    Http::assertSent(fn (Request $request) => $request['to'] === '5511999999999'
        && $request['reaction'] === ['message_id' => 'wamid.inbound', 'emoji' => '👍']);
});
