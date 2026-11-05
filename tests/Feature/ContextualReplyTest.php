<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Events\MessageDelivered;
use Multek\LaravelWhatsAppCloud\Events\MessageFailed;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSendMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Support\MessageBuilder;
use Multek\LaravelWhatsAppCloud\Support\WebhookProcessor;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Http::fake(['*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200)]);
    Queue::fake();

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->otherPhone = fn () => WhatsAppPhone::create([
        'key' => 'other',
        'phone_id' => 'other_phone_id',
        'phone_number' => '+15557654321',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->builder = fn () => app(WhatsAppManager::class)->phone('test')->to('5511999999999');

    $this->webhook = fn (array $value) => app(WebhookProcessor::class)->process([
        'entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => 'test_phone_id']] + $value,
        ]]]],
    ]);

    $this->storeMessage = fn (string $messageId, ?string $replyTo = null, ?WhatsAppPhone $phone = null) => WhatsAppMessage::create([
        'whatsapp_phone_id' => ($phone ?? $this->phone)->id,
        'message_id' => $messageId,
        'reply_to_message_id' => $replyTo,
        'direction' => WhatsAppMessage::DIRECTION_INBOUND,
        'type' => 'text',
        'from' => '+5511999999999',
        'to' => '+15551234567',
        'status' => WhatsAppMessage::STATUS_PROCESSED,
    ]);
});

it('stores the quoted wamid from an inbound message of any type', function (string $type, array $body) {
    ($this->webhook)(['messages' => [[
        'id' => 'wamid.in',
        'from' => '5511999999999',
        'timestamp' => '1700000000',
        'type' => $type,
        'context' => ['from' => '15551234567', 'id' => 'wamid.quoted'],
        $type => $body,
    ]]]);

    expect(WhatsAppMessage::firstWhere('message_id', 'wamid.in')->reply_to_message_id)->toBe('wamid.quoted');
})->with([
    'text' => ['text', ['body' => 'here is the updated quote']],
    'document' => ['document', ['id' => 'media_1', 'mime_type' => 'application/pdf', 'filename' => 'quote.pdf']],
]);

it('leaves the column null for an inbound message with no context', function () {
    ($this->webhook)(['messages' => [[
        'id' => 'wamid.in',
        'from' => '5511999999999',
        'timestamp' => '1700000000',
        'type' => 'text',
        'text' => ['body' => 'hi'],
    ]]]);

    expect(WhatsAppMessage::firstWhere('message_id', 'wamid.in')->reply_to_message_id)->toBeNull();
});

it('leaves the column null for a forwarded message, whose context has no id', function (array $context) {
    ($this->webhook)(['messages' => [[
        'id' => 'wamid.in',
        'from' => '5511999999999',
        'timestamp' => '1700000000',
        'type' => 'text',
        'context' => $context,
        'text' => ['body' => 'fwd'],
    ]]]);

    expect(WhatsAppMessage::firstWhere('message_id', 'wamid.in')->reply_to_message_id)->toBeNull();
})->with([
    'forwarded' => [['forwarded' => true]],
    'frequently forwarded' => [['frequently_forwarded' => true]],
]);

it('sends the context and stores the id on send()', function () {
    $message = ($this->builder)()->replyTo('wamid.quoted')->text('thanks')->send();

    expect($message->reply_to_message_id)->toBe('wamid.quoted');

    Http::assertSent(fn (Request $request) => $request['context'] === ['message_id' => 'wamid.quoted']
        && $request['text']['body'] === 'thanks');
});

it('keeps the id through queue() and the send job', function () {
    $pending = ($this->builder)()->replyTo('wamid.quoted')->text('thanks')->queue();

    Http::assertNothingSent();
    expect($pending->reply_to_message_id)->toBe('wamid.quoted');

    (new WhatsAppSendMessage($pending))->handle();

    Http::assertSent(fn (Request $request) => $request['context'] === ['message_id' => 'wamid.quoted']);
});

it('adds the context to an interactive message sent through the job', function () {
    $pending = ($this->builder)()->replyTo('wamid.quoted')->buttons('Pick one')->button('a', 'A')->queue();

    (new WhatsAppSendMessage($pending))->handle();

    Http::assertSent(fn (Request $request) => $request['type'] === 'interactive'
        && $request['context'] === ['message_id' => 'wamid.quoted']);
});

it('allows a template as a reply', function () {
    ($this->builder)()->replyTo('wamid.quoted')->template('order_update')->send();

    Http::assertSent(fn (Request $request) => $request['type'] === 'template'
        && $request['context'] === ['message_id' => 'wamid.quoted']);
});

it('sends no context on a plain message, sent or queued', function () {
    ($this->builder)()->text('plain')->send();
    (new WhatsAppSendMessage(($this->builder)()->text('plain')->queue()))->handle();

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => isset($request['context']));
});

it('does not leak the context to the next message on the shared client', function () {
    $manager = app(WhatsAppManager::class)->phone('test');

    $manager->to('5511999999999')->replyTo('wamid.quoted')->text('reply')->send();
    $manager->to('5511999999999')->text('next')->send();

    Http::assertSent(fn (Request $request) => $request['text']['body'] === 'next' && ! isset($request['context']));
});

it('refuses replyTo() with reaction(), in either order', function (string $method) {
    $replyFirst = ($this->builder)()->replyTo('wamid.quoted')->reaction('wamid.quoted', '👍');
    $reactionFirst = ($this->builder)()->reaction('wamid.quoted', '👍')->replyTo('wamid.quoted');

    expect(fn () => $replyFirst->{$method}())->toThrow(InvalidArgumentException::class, 'contextual reply')
        ->and(fn () => $reactionFirst->{$method}())->toThrow(InvalidArgumentException::class, 'contextual reply');

    Http::assertNothingSent();
    expect(WhatsAppMessage::count())->toBe(0);
})->with(['send', 'queue']);

it('refuses to send with replyTo() on a client that cannot send a context, before any upload', function () {
    $client = Mockery::mock(WhatsAppClientInterface::class);
    $client->shouldNotReceive('uploadMediaContents');

    $builder = (new MessageBuilder($this->phone, $client))->to('5511999999999')->replyTo('wamid.quoted')->document(UploadedFile::fake()->create('quote.pdf'));

    expect(fn () => $builder->send())->toThrow(LogicException::class, 'WhatsAppClient');
});

it('queues a reply on any client, because the job sends it with its own', function () {
    $client = Mockery::mock(WhatsAppClientInterface::class);

    $pending = (new MessageBuilder($this->phone, $client))->to('5511999999999')->replyTo('wamid.quoted')->text('thanks')->queue();

    (new WhatsAppSendMessage($pending))->handle();

    Http::assertSent(fn (Request $request) => $request['context'] === ['message_id' => 'wamid.quoted']);
});

it('refuses to reply to an id Meta does not know', function (string $messageId) {
    expect(fn () => ($this->builder)()->replyTo($messageId))
        ->toThrow(InvalidArgumentException::class, $messageId);
})->with(['pending_abc', 'unknown_abc']);

it('keeps plain messages working while the column migration is pending', function () {
    Schema::table('whatsapp_messages', fn (Blueprint $table) => $table->dropColumn('reply_to_message_id'));

    ($this->webhook)(['messages' => [[
        'id' => 'wamid.in',
        'from' => '5511999999999',
        'timestamp' => '1700000000',
        'type' => 'text',
        'text' => ['body' => 'hi'],
    ]]]);

    expect(WhatsAppMessage::firstWhere('message_id', 'wamid.in'))->not->toBeNull()
        ->and(($this->builder)()->text('sent')->send()->exists)->toBeTrue()
        ->and(($this->builder)()->text('queued')->queue()->exists)->toBeTrue();
});

it('resolves repliedTo to the stored message', function () {
    $original = ($this->storeMessage)('wamid.quoted');
    $reply = ($this->storeMessage)('wamid.reply', 'wamid.quoted');

    expect($reply->repliedTo->id)->toBe($original->id);
});

it('returns null from repliedTo when the message is not stored or not a reply', function () {
    expect(($this->storeMessage)('wamid.reply', 'wamid.missing')->repliedTo)->toBeNull()
        ->and(($this->storeMessage)('wamid.plain')->repliedTo)->toBeNull();
});

it('does not resolve repliedTo to a message stored under another phone', function () {
    ($this->storeMessage)('wamid.quoted', null, ($this->otherPhone)());

    expect(($this->storeMessage)('wamid.reply', 'wamid.quoted')->repliedTo)->toBeNull();
});

it('eager loads repliedTo', function () {
    $original = ($this->storeMessage)('wamid.quoted');
    ($this->storeMessage)('wamid.reply', 'wamid.quoted');
    ($this->storeMessage)('wamid.orphan', 'wamid.missing');

    $messages = WhatsAppMessage::with('repliedTo')->get()->keyBy('message_id');

    expect($messages['wamid.reply']->repliedTo->id)->toBe($original->id)
        ->and($messages['wamid.orphan']->repliedTo)->toBeNull();
});

it('keeps repliedTo on the same phone through with(), load() and whereHas()', function () {
    $other = ($this->otherPhone)();
    $sharedQuote = ($this->storeMessage)('wamid.other', null, $other);
    $sameQuote = ($this->storeMessage)('wamid.same');

    $crossPhone = ($this->storeMessage)('wamid.cross', 'wamid.other');
    $samePhone = ($this->storeMessage)('wamid.reply', 'wamid.same');
    $otherPhoneReply = ($this->storeMessage)('wamid.other_reply', 'wamid.other', $other);

    $messages = WhatsAppMessage::with('repliedTo')->get()->keyBy('message_id');

    expect($messages['wamid.cross']->repliedTo)->toBeNull()
        ->and($messages['wamid.reply']->repliedTo->id)->toBe($sameQuote->id)
        ->and($messages['wamid.other_reply']->repliedTo->id)->toBe($sharedQuote->id)
        ->and($crossPhone->load('repliedTo')->repliedTo)->toBeNull()
        ->and(WhatsAppMessage::whereHas('repliedTo')->pluck('message_id')->sort()->values()->all())
        ->toBe(['wamid.other_reply', 'wamid.reply'])
        ->and(WhatsAppMessage::whereDoesntHave('repliedTo')->whereNotNull('reply_to_message_id')->pluck('message_id')->all())
        ->toBe(['wamid.cross']);
});

it('resolves repliedTo on a row selected without its phone column', function () {
    $original = ($this->storeMessage)('wamid.quoted');
    ($this->storeMessage)('wamid.reply', 'wamid.quoted');

    $partial = fn () => WhatsAppMessage::select('id', 'reply_to_message_id')->firstWhere('message_id', 'wamid.reply');

    expect($partial()->repliedTo->id)->toBe($original->id)
        ->and($partial()->load('repliedTo')->repliedTo->id)->toBe($original->id);
});

it('logs an error the webhook reports with no message id', function () {
    Log::spy();

    ($this->webhook)(['errors' => [['code' => 131009, 'title' => 'Parameter value is not valid']]]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['phone_id'] === 'test_phone_id'
        && $context['error']['code'] === 131009);
});

it('does not let a late status undo a later one', function (array $statuses, string $expected) {
    $message = ($this->builder)()->text('hi')->send();

    foreach ($statuses as $status) {
        ($this->webhook)(['statuses' => [[
            'id' => $message->message_id,
            'status' => $status,
            'timestamp' => '1700000000',
            'errors' => [['code' => 131009, 'message' => 'Parameter value is not valid']],
        ]]]);
    }

    expect($message->fresh()->delivery_status)->toBe($expected);
})->with([
    'sent after failed' => [['failed', 'sent'], 'failed'],
    'delivered after failed' => [['failed', 'delivered'], 'failed'],
    'sent after delivered' => [['delivered', 'sent'], 'delivered'],
    'delivered after read' => [['read', 'delivered'], 'read'],
    'in order' => [['sent', 'delivered', 'read'], 'read'],
    'failed after delivered' => [['delivered', 'failed'], 'failed'],
    'unknown status' => [['sent', 'deleted'], 'deleted'],
]);

it('still records the timestamp and fires the event of a late status', function () {
    Event::fake([MessageDelivered::class]);

    $message = ($this->builder)()->text('hi')->send();

    foreach (['read', 'delivered'] as $status) {
        ($this->webhook)(['statuses' => [['id' => $message->message_id, 'status' => $status, 'timestamp' => '1700000000']]]);
    }

    expect($message->fresh()->delivered_at)->not->toBeNull()
        ->and($message->fresh()->delivery_status)->toBe('read');

    Event::assertDispatched(MessageDelivered::class);
});

it('records a failed reaction reported as a failed status with error 131009', function () {
    Event::fake([MessageFailed::class]);

    $reaction = app(WhatsAppManager::class)->phone('test')->sendReaction('wamid.old', '👍', '5511999999999');

    ($this->webhook)(['statuses' => [[
        'id' => $reaction->message_id,
        'status' => 'failed',
        'timestamp' => '1700000000',
        'recipient_id' => '5511999999999',
        'errors' => [['code' => 131009, 'title' => 'Parameter value is not valid', 'message' => 'Parameter value is not valid']],
    ]]]);

    expect($reaction->fresh()->delivery_status)->toBe(WhatsAppMessage::DELIVERY_STATUS_FAILED)
        ->and($reaction->fresh()->error_message)->toBe('Parameter value is not valid');

    Event::assertDispatched(MessageFailed::class);
});
