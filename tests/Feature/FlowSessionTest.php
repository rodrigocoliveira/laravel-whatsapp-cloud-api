<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Multek\LaravelWhatsAppCloud\Contracts\FlowHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowRequest;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowResponse;
use Multek\LaravelWhatsAppCloud\DTOs\IncomingMessageContext;
use Multek\LaravelWhatsAppCloud\Events\FlowCompleted;
use Multek\LaravelWhatsAppCloud\Exceptions\FlowTokenException;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppFlow;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppFlowSession;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    [$this->privateKey, $this->publicKey] = generateFlowKeyPair();

    config()->set('whatsapp.flows.endpoint_enabled', true);
    config()->set('whatsapp.flows.private_key', $this->privateKey);
    config()->set('whatsapp.flows.handler', null);

    $this->phone = WhatsAppPhone::create([
        'key' => 'vendas',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->flow = WhatsAppFlow::create([
        'whatsapp_phone_id' => $this->phone->id,
        'flow_id' => '1001',
        'name' => 'criar_pedido',
        'status' => WhatsAppFlow::STATUS_PUBLISHED,
        'handler' => OrderFlowHandler::class,
    ]);

    OrderFlowHandler::$received = null;
    Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.flow']]], 200)]);
});

function sendOrderFlow(string $token = 'pedido-1'): WhatsAppMessage
{
    return app(WhatsAppManager::class)
        ->phone('vendas')
        ->to('5511999999999')
        ->flowNamed('criar_pedido', 'Vamos abrir seu pedido', 'Abrir pedido')
        ->flowToken($token)
        ->send();
}

/**
 * @param  array<string, mixed>  $data
 * @return array{status: int, data: array<string, mixed>|null}
 */
function exchangeOrderFlow(string $publicKey, string $screen, array $data = [], string $token = 'pedido-1'): array
{
    return postFlowRequest([
        'version' => '3.0',
        'action' => 'data_exchange',
        'screen' => $screen,
        'data' => $data,
        'flow_token' => $token,
    ], $publicKey);
}

function postOrderFlowReply(string $messageId, string $token = 'pedido-1'): void
{
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '15551234567', 'phone_number_id' => 'test_phone_id'],
                    'contacts' => [['profile' => ['name' => 'João'], 'wa_id' => '5511999999999']],
                    'messages' => [[
                        'from' => '5511999999999',
                        'id' => $messageId,
                        'timestamp' => (string) time(),
                        'type' => 'interactive',
                        'interactive' => [
                            'type' => 'nfm_reply',
                            'nfm_reply' => [
                                'name' => 'flow',
                                'body' => 'Sent',
                                'response_json' => json_encode(['flow_token' => $token, 'material' => 'cimento']),
                            ],
                        ],
                    ]],
                ],
            ]],
        ]],
    ];

    test()->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => test()->generateSignature($payload),
    ])->assertOk();
}

it('sends a synced flow by name and records its session', function () {
    $message = sendOrderFlow();

    $session = WhatsAppFlowSession::firstWhere('flow_token', 'pedido-1');

    expect($message->content['flow_id'])->toBe('1001')
        ->and($message->content['flow_action'])->toBe('data_exchange')
        ->and($session->whatsapp_flow_id)->toBe($this->flow->id)
        ->and($session->whatsapp_message_id)->toBe($message->id)
        ->and($session->status)->toBe(WhatsAppFlowSession::STATUS_SENT);
});

it('refuses to send a flow name the phone does not have', function () {
    app(WhatsAppManager::class)->phone('vendas')->to('5511999999999')
        ->flowNamed('nao_existe', 'Oi', 'Abrir');
})->throws(InvalidArgumentException::class, 'whatsapp:sync-flows');

it('records a session for a flow sent by raw id and links the synced flow', function () {
    app(WhatsAppManager::class)->phone('vendas')->to('5511999999999')
        ->flow('Oi', '1001', 'Abrir')->flowToken('raw-1')->queue();

    expect(WhatsAppFlowSession::firstWhere('flow_token', 'raw-1')->whatsapp_flow_id)->toBe($this->flow->id);
});

it('routes to the flow handler with the conversation behind the token', function () {
    $message = sendOrderFlow();

    $result = exchangeOrderFlow($this->publicKey, 'DADOS', ['material' => 'cimento']);

    expect($result['status'])->toBe(200)
        ->and($result['data']['screen'])->toBe('CONFIRMAR')
        ->and(OrderFlowHandler::$received->conversation()->id)->toBe($message->whatsapp_conversation_id)
        ->and(OrderFlowHandler::$received->phone()->key)->toBe('vendas')
        ->and(OrderFlowHandler::$received->flow()->name)->toBe('criar_pedido')
        ->and(OrderFlowHandler::$received->session()->state)->toBe(['material' => 'cimento'])
        ->and(WhatsAppFlowSession::first()->status)->toBe(WhatsAppFlowSession::STATUS_IN_PROGRESS);
});

it('completes the session and refuses the same message afterwards with 427', function () {
    sendOrderFlow();
    exchangeOrderFlow($this->publicKey, 'DADOS', ['material' => 'cimento']);

    $done = exchangeOrderFlow($this->publicKey, 'CONFIRMAR');
    $session = WhatsAppFlowSession::first();

    expect($done['data']['screen'])->toBe('SUCCESS')
        ->and($session->status)->toBe(WhatsAppFlowSession::STATUS_COMPLETED)
        ->and($session->result)->toBe(['pedido_id' => 123]);

    config()->set('whatsapp.flows.token_no_longer_valid_message', 'Pedido já realizado');

    expect(exchangeOrderFlow($this->publicKey, 'DADOS'))
        ->toBe(['status' => 427, 'data' => ['error_msg' => 'Pedido já realizado']]);
});

it('lets a flow opt out of single use', function () {
    $this->flow->update(['single_use' => false]);
    sendOrderFlow();
    exchangeOrderFlow($this->publicKey, 'CONFIRMAR');

    expect(exchangeOrderFlow($this->publicKey, 'DADOS')['status'])->toBe(200);
});

it('expires a session after its ttl', function () {
    config()->set('whatsapp.flows.session_ttl_hours', 2);
    sendOrderFlow();

    $this->travel(3)->hours();

    expect(exchangeOrderFlow($this->publicKey, 'DADOS')['status'])->toBe(427)
        ->and(WhatsAppFlowSession::first()->status)->toBe(WhatsAppFlowSession::STATUS_EXPIRED);
});

it('starts a reused token over on the newest message', function () {
    config()->set('whatsapp.flows.session_ttl_hours', 2);
    sendOrderFlow();
    exchangeOrderFlow($this->publicKey, 'CONFIRMAR');

    $this->travel(3)->hours();
    // queued and never run, since the fake returns the same wamid for every send
    Queue::fake();
    $message = app(WhatsAppManager::class)->phone('vendas')->to('5511999999999')
        ->flowNamed('criar_pedido', 'De novo', 'Abrir')->flowToken('pedido-1')->queue();

    expect(exchangeOrderFlow($this->publicKey, 'DADOS')['status'])->toBe(200)
        ->and(WhatsAppFlowSession::sole()->whatsapp_message_id)->toBe($message->id);
});

it('answers 427 when the handler throws FlowTokenException', function () {
    sendOrderFlow();

    expect(exchangeOrderFlow($this->publicKey, 'CANCELADO'))
        ->toBe(['status' => 427, 'data' => ['error_msg' => 'Pedido cancelado']]);
});

it('falls back to the configured handler for tokens without a session', function () {
    config()->set('whatsapp.flows.handler', OrderFlowHandler::class);

    $result = exchangeOrderFlow($this->publicKey, 'DADOS', [], 'unknown-token');

    expect($result['status'])->toBe(200)
        ->and(OrderFlowHandler::$received->session())->toBeNull()
        ->and(OrderFlowHandler::$received->conversation())->toBeNull();
});

it('rejects an unsigned flow request with 432', function () {
    $encrypted = encryptFlowRequest(['version' => '3.0', 'action' => 'ping'], $this->publicKey);

    $this->postJson('/webhooks/whatsapp/flow', $encrypted['payload'])->assertStatus(432);
    $this->withHeader('X-Hub-Signature-256', 'sha256=bad')
        ->postJson('/webhooks/whatsapp/flow', $encrypted['payload'])->assertStatus(432);
});

it('links the nfm_reply to its session and exposes it to the message handler', function () {
    Event::fake([FlowCompleted::class]);
    sendOrderFlow();

    postOrderFlowReply('wamid.reply1');

    $reply = WhatsAppMessage::firstWhere('message_id', 'wamid.reply1');
    $session = WhatsAppFlowSession::first();
    $batch = WhatsAppMessageBatch::create([
        'whatsapp_phone_id' => $this->phone->id,
        'whatsapp_conversation_id' => $reply->whatsapp_conversation_id,
        'first_message_at' => now(),
        'process_after' => now(),
    ]);
    $context = new IncomingMessageContext($this->phone, $reply->conversation, $batch, collect([$reply]));

    expect($session->response_message_id)->toBe($reply->id)
        ->and($session->status)->toBe(WhatsAppFlowSession::STATUS_COMPLETED)
        ->and($session->result)->toBe(['material' => 'cimento'])
        ->and($context->getCompletedFlows()->sole()->flow->name)->toBe('criar_pedido');

    Event::assertDispatched(FlowCompleted::class, fn ($event) => $event->session->is($session));
});

it('filters a second submission of the same flow message', function () {
    sendOrderFlow();
    postOrderFlowReply('wamid.reply1');
    postOrderFlowReply('wamid.reply2');

    expect(WhatsAppMessage::firstWhere('message_id', 'wamid.reply2')->status)->toBe(WhatsAppMessage::STATUS_FILTERED)
        ->and(WhatsAppFlowSession::first()->response_message_id)
        ->toBe(WhatsAppMessage::firstWhere('message_id', 'wamid.reply1')->id);
});

it('keeps the endpoint result when the nfm_reply arrives', function () {
    sendOrderFlow();
    exchangeOrderFlow($this->publicKey, 'CONFIRMAR');

    postOrderFlowReply('wamid.reply1');

    expect(WhatsAppFlowSession::first()->result)->toBe(['pedido_id' => 123]);
});

it('syncs flows from Meta without touching app settings', function () {
    Http::fake(['*/test_waba/flows*' => Http::response(['data' => [
        ['id' => '1001', 'name' => 'criar_pedido', 'status' => 'PUBLISHED', 'categories' => ['OTHER'], 'validation_errors' => []],
        ['id' => '1002', 'name' => 'criar_projeto', 'status' => 'DRAFT', 'categories' => ['OTHER'], 'validation_errors' => []],
    ]])]);

    WhatsAppFlow::create([
        'whatsapp_phone_id' => $this->phone->id, 'flow_id' => '999', 'name' => 'antigo', 'status' => 'DRAFT',
    ]);

    $this->artisan('whatsapp:sync-flows', ['--phone' => 'vendas'])
        ->expectsOutputToContain('Flow sync completed successfully!')
        ->assertSuccessful();

    expect(WhatsAppFlow::firstWhere('flow_id', '1001')->handler)->toBe(OrderFlowHandler::class)
        ->and(WhatsAppFlow::firstWhere('flow_id', '1002')->status)->toBe('DRAFT')
        ->and(WhatsAppFlow::firstWhere('flow_id', '999')->status)->toBe(WhatsAppFlow::STATUS_DELETED)
        ->and($this->phone->flows()->published()->pluck('name')->all())->toBe(['criar_pedido']);
});

it('builds an in-screen error response', function () {
    expect(FlowResponse::error('DADOS', 'CNPJ inválido', ['cnpj' => '1'])->toArray('3.0'))->toBe([
        'version' => '3.0',
        'screen' => 'DADOS',
        'data' => ['error_message' => 'CNPJ inválido', 'cnpj' => '1'],
    ]);
});

class OrderFlowHandler implements FlowHandlerInterface
{
    public static ?FlowRequest $received = null;

    public function handle(FlowRequest $request): FlowResponse
    {
        self::$received = $request;

        return match ($request->screen) {
            'CANCELADO' => throw FlowTokenException::noLongerValid('Pedido cancelado'),
            'CONFIRMAR' => FlowResponse::complete((string) $request->flowToken, ['pedido_id' => 123]),
            default => $this->remember($request),
        };
    }

    private function remember(FlowRequest $request): FlowResponse
    {
        $request->session()?->remember($request->data);

        return FlowResponse::screen('CONFIRMAR');
    }
}
