<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\DTOs\IncomingMessageContext;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\FlowResponseContent;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);
});

it('sends a flow cta message with the expected payload', function () {
    Http::fake([
        '*' => Http::response(['messages' => [['id' => 'wamid.flow']]], 200),
    ]);

    $client = new WhatsAppClient($this->phone);

    $client->sendFlow(
        to: '5511999999999',
        body: 'Complete seu cadastro',
        flowId: '1234567890',
        flowCta: 'Cadastrar',
        flowToken: 'signup-42',
        screen: 'WELCOME',
        data: ['name' => 'Rodrigo'],
        header: 'Cadastro',
        footer: 'Leva 1 minuto',
    );

    Http::assertSent(function ($request) {
        $interactive = $request->data()['interactive'];

        expect($request->data()['type'])->toBe('interactive')
            ->and($interactive['type'])->toBe('flow')
            ->and($interactive['body']['text'])->toBe('Complete seu cadastro')
            ->and($interactive['header'])->toBe(['type' => 'text', 'text' => 'Cadastro'])
            ->and($interactive['footer'])->toBe(['text' => 'Leva 1 minuto'])
            ->and($interactive['action']['name'])->toBe('flow')
            ->and($interactive['action']['parameters'])->toBe([
                'flow_message_version' => '3',
                'flow_id' => '1234567890',
                'flow_token' => 'signup-42',
                'flow_cta' => 'Cadastrar',
                'flow_action' => 'navigate',
                'flow_action_payload' => [
                    'screen' => 'WELCOME',
                    'data' => ['name' => 'Rodrigo'],
                ],
            ]);

        return true;
    });
});

it('omits the flow action payload when no screen is given', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.flow']]], 200)]);

    (new WhatsAppClient($this->phone))->sendFlow(
        to: '5511999999999',
        body: 'Responda a pesquisa',
        flowId: '999',
        flowCta: 'Abrir',
        flowToken: 'survey-1',
    );

    Http::assertSent(function ($request) {
        $parameters = $request->data()['interactive']['action']['parameters'];

        expect($parameters)->not->toHaveKey('flow_action_payload')
            ->and($parameters)->not->toHaveKey('mode');

        return true;
    });
});

it('sends a draft flow in data_exchange mode', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.flow']]], 200)]);

    (new WhatsAppClient($this->phone))->sendFlow(
        to: '5511999999999',
        body: 'Teste',
        flowId: '999',
        flowCta: 'Abrir',
        flowToken: 'draft-1',
        flowAction: 'data_exchange',
        mode: 'draft',
    );

    Http::assertSent(function ($request) {
        $parameters = $request->data()['interactive']['action']['parameters'];

        expect($parameters['flow_action'])->toBe('data_exchange')
            ->and($parameters['mode'])->toBe('draft');

        return true;
    });
});

it('builds a flow message through the fluent builder', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.flow']]], 200)]);

    $message = app(WhatsAppManager::class)
        ->phone('test')
        ->to('5511999999999')
        ->flow('Complete seu cadastro', '1234567890', 'Cadastrar')
        ->flowToken('signup-42')
        ->flowScreen('WELCOME', ['name' => 'Rodrigo'])
        ->footer('Leva 1 minuto')
        ->send();

    expect($message->type)->toBe(WhatsAppMessage::TYPE_INTERACTIVE)
        ->and($message->content['type'])->toBe('flow')
        ->and($message->content['flow_id'])->toBe('1234567890')
        ->and($message->content['flow_token'])->toBe('signup-42')
        ->and($message->content['flow_cta'])->toBe('Cadastrar')
        ->and($message->content['flow_action'])->toBe('navigate')
        ->and($message->content['screen'])->toBe('WELCOME')
        ->and($message->content['data'])->toBe(['name' => 'Rodrigo'])
        ->and($message->content['footer'])->toBe('Leva 1 minuto');
});

it('generates a flow token when none is provided', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.flow']]], 200)]);

    $message = app(WhatsAppManager::class)
        ->phone('test')
        ->to('5511999999999')
        ->flow('Oi', '123', 'Abrir')
        ->send();

    expect($message->content['flow_token'])->not->toBeEmpty();
});

it('processes an nfm_reply webhook into a flow response', function () {
    $responseJson = json_encode([
        'flow_token' => 'signup-42',
        'name' => 'Rodrigo',
        'email' => 'rodrigo@example.com',
    ]);

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '15551234567',
                        'phone_number_id' => 'test_phone_id',
                    ],
                    'contacts' => [[
                        'profile' => ['name' => 'Test User'],
                        'wa_id' => '5511999999999',
                    ]],
                    'messages' => [[
                        'from' => '5511999999999',
                        'id' => 'wamid.nfm',
                        'timestamp' => (string) time(),
                        'type' => 'interactive',
                        'interactive' => [
                            'type' => 'nfm_reply',
                            'nfm_reply' => [
                                'name' => 'flow',
                                'body' => 'Sent',
                                'response_json' => $responseJson,
                            ],
                        ],
                    ]],
                ],
            ]],
        ]],
    ];

    $response = $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => $this->generateSignature($payload),
    ]);

    $response->assertOk();

    $message = WhatsAppMessage::where('message_id', 'wamid.nfm')->firstOrFail();

    expect($message->isFlowResponse())->toBeTrue()
        ->and($message->isInteractiveReply())->toBeFalse()
        ->and($message->getFlowData())->toBe([
            'flow_token' => 'signup-42',
            'name' => 'Rodrigo',
            'email' => 'rodrigo@example.com',
        ]);

    $content = $message->getTypedContent();

    expect($content)->toBeInstanceOf(FlowResponseContent::class)
        ->and($content->flowToken)->toBe('signup-42')
        ->and($content->body)->toBe('Sent')
        ->and($content->name)->toBe('flow')
        ->and($content->get('email'))->toBe('rodrigo@example.com')
        ->and($content->responseJson)->toBe($responseJson);
});

it('exposes flow responses on the incoming message context', function () {
    $conversation = WhatsAppConversation::create([
        'whatsapp_phone_id' => $this->phone->id,
        'contact_phone' => '5511999999999',
        'contact_name' => 'Test User',
        'last_message_at' => now(),
    ]);

    $batch = WhatsAppMessageBatch::create([
        'whatsapp_phone_id' => $this->phone->id,
        'whatsapp_conversation_id' => $conversation->id,
        'first_message_at' => now(),
        'process_after' => now(),
    ]);

    $flowMessage = WhatsAppMessage::create([
        'whatsapp_phone_id' => $this->phone->id,
        'whatsapp_conversation_id' => $conversation->id,
        'message_id' => 'wamid.ctx',
        'direction' => WhatsAppMessage::DIRECTION_INBOUND,
        'type' => WhatsAppMessage::TYPE_INTERACTIVE,
        'from' => '5511999999999',
        'to' => '+15551234567',
        'content' => [
            'type' => 'nfm_reply',
            'nfm_reply' => [
                'name' => 'flow',
                'body' => 'Sent',
                'response_json' => json_encode(['flow_token' => 't', 'age' => 30]),
            ],
        ],
        'status' => WhatsAppMessage::STATUS_RECEIVED,
    ]);

    $textMessage = WhatsAppMessage::create([
        'whatsapp_phone_id' => $this->phone->id,
        'whatsapp_conversation_id' => $conversation->id,
        'message_id' => 'wamid.ctx2',
        'direction' => WhatsAppMessage::DIRECTION_INBOUND,
        'type' => WhatsAppMessage::TYPE_TEXT,
        'from' => '5511999999999',
        'to' => '+15551234567',
        'content' => ['body' => 'oi'],
        'text_body' => 'oi',
        'status' => WhatsAppMessage::STATUS_RECEIVED,
    ]);

    $context = new IncomingMessageContext(
        phone: $this->phone,
        conversation: $conversation,
        batch: $batch,
        messages: collect([$flowMessage, $textMessage]),
    );

    expect($context->getFlowResponses())->toHaveCount(1)
        ->and($context->getFlowResponses()->first()->is($flowMessage))->toBeTrue()
        ->and($context->getFlowData())->toBe([['flow_token' => 't', 'age' => 30]]);
});
