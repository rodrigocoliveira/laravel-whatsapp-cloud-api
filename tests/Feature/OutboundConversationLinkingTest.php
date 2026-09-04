<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\DTOs\IncomingMessageContext;
use Multek\LaravelWhatsAppCloud\Events\MessageSent;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Support\MessageBuilder;
use Multek\LaravelWhatsAppCloud\Support\TemplateBuilder;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Http::fake([
        '*' => Http::response(['messages' => [['id' => 'wamid.outbound']]], 200),
    ]);

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->manager = app(WhatsAppManager::class);
});

it('links a synchronously sent message to a conversation resolved from phone and recipient', function () {
    $message = $this->manager->phone('test')->sendText('5511999999999', 'Hello');

    $conversation = WhatsAppConversation::where('whatsapp_phone_id', $this->phone->id)
        ->where('contact_phone', '+5511999999999')
        ->first();

    expect($conversation)->not->toBeNull()
        ->and($message->whatsapp_conversation_id)->toBe($conversation->id)
        ->and($conversation->status)->toBe(WhatsAppConversation::STATUS_ACTIVE)
        ->and($conversation->messages()->pluck('id')->all())->toBe([$message->id]);
});

it('reuses the existing conversation for a recipient given without the plus prefix', function () {
    $existing = WhatsAppConversation::create([
        'whatsapp_phone_id' => $this->phone->id,
        'contact_phone' => '+5511999999999',
        'last_message_at' => now()->subDay(),
        'status' => WhatsAppConversation::STATUS_ACTIVE,
    ]);

    $message = $this->manager->phone('test')->sendText('5511999999999', 'Hello again');

    expect(WhatsAppConversation::count())->toBe(1)
        ->and($message->whatsapp_conversation_id)->toBe($existing->id)
        ->and($existing->fresh()->last_message_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('fires MessageSent after a synchronous send', function () {
    Event::fake([MessageSent::class]);

    $message = $this->manager->phone('test')->sendText('5511999999999', 'Hello');

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->is($message));
});

it('links a queued message to its conversation before the job runs', function () {
    Queue::fake();

    $message = $this->manager->phone('test')->to('5511999999999')->text('Queued')->queue();

    $conversation = WhatsAppConversation::where('contact_phone', '+5511999999999')->first();

    expect($conversation)->not->toBeNull()
        ->and($message->whatsapp_conversation_id)->toBe($conversation->id);
});

it('accepts an explicit conversation on the builder and targets its contact', function () {
    $conversation = WhatsAppConversation::create([
        'whatsapp_phone_id' => $this->phone->id,
        'contact_phone' => '+5511888888888',
        'last_message_at' => now(),
        'status' => WhatsAppConversation::STATUS_ACTIVE,
    ]);

    $message = $this->manager->phone('test')->conversation($conversation)->text('Hi')->send();

    expect($message->to)->toBe('+5511888888888')
        ->and($message->whatsapp_conversation_id)->toBe($conversation->id)
        ->and(WhatsAppConversation::count())->toBe(1);
});

it('links handler replies to the conversation held by the context', function () {
    $conversation = WhatsAppConversation::create([
        'whatsapp_phone_id' => $this->phone->id,
        'contact_phone' => '+5511999999999',
        'last_message_at' => now(),
        'status' => WhatsAppConversation::STATUS_ACTIVE,
    ]);

    $batch = WhatsAppMessageBatch::create([
        'whatsapp_phone_id' => $this->phone->id,
        'whatsapp_conversation_id' => $conversation->id,
        'status' => 'processing',
        'first_message_at' => now(),
        'process_after' => now(),
    ]);

    $context = new IncomingMessageContext(
        phone: $this->phone,
        conversation: $conversation,
        batch: $batch,
        messages: new Collection,
    );

    $reply = $context->reply('Agent reply');

    expect($reply->whatsapp_conversation_id)->toBe($conversation->id)
        ->and($conversation->messages()->pluck('id')->all())->toBe([$reply->id]);
});

it('links template sends to a conversation and fires MessageSent', function () {
    Event::fake([MessageSent::class]);

    $builder = new TemplateBuilder($this->phone, new WhatsAppClient($this->phone), '5511999999999');

    $message = $builder->name('hello_world')->send();

    $conversation = WhatsAppConversation::where('contact_phone', '+5511999999999')->first();

    expect($message->type)->toBe('template')
        ->and($conversation)->not->toBeNull()
        ->and($message->whatsapp_conversation_id)->toBe($conversation->id);

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->is($message));
});

it('links reactions to the conversation of their recipient', function () {
    $message = $this->manager->phone('test')->to('5511999999999')->reaction('wamid.original', '👍')->send();

    $conversation = WhatsAppConversation::where('contact_phone', '+5511999999999')->first();

    expect($conversation)->not->toBeNull()
        ->and($message->whatsapp_conversation_id)->toBe($conversation->id);
});

it('refuses to send without a recipient instead of creating an empty conversation', function () {
    $builder = new MessageBuilder($this->phone, new WhatsAppClient($this->phone));

    expect(fn () => $builder->text('No recipient')->send())
        ->toThrow(InvalidArgumentException::class);

    expect(WhatsAppConversation::count())->toBe(0);
});
