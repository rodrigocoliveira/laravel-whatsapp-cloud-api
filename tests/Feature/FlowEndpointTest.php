<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Contracts\FlowHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowRequest;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowResponse;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

beforeEach(function () {
    [$this->privateKey, $this->publicKey] = generateFlowKeyPair();

    config()->set('whatsapp.flows.endpoint_enabled', true);
    config()->set('whatsapp.flows.private_key', $this->privateKey);
    config()->set('whatsapp.flows.handler', null);

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);
});

/**
 * Post an encrypted request to the flow endpoint and return the decrypted response.
 *
 * @param  array<string, mixed>  $body
 * @return array{status: int, data: array<string, mixed>|null}
 */
function postFlowRequest(array $body, string $publicKey): array
{
    $encrypted = encryptFlowRequest($body, $publicKey);

    $response = test()->postJson('/webhooks/whatsapp/flow', $encrypted['payload']);

    if ($response->getStatusCode() !== 200) {
        return ['status' => $response->getStatusCode(), 'data' => null];
    }

    $raw = base64_decode($response->getContent());
    $flippedIv = $encrypted['iv'] ^ str_repeat("\xff", strlen($encrypted['iv']));

    $plaintext = openssl_decrypt(
        substr($raw, 0, -16),
        'aes-'.(strlen($encrypted['aesKey']) * 8).'-gcm',
        $encrypted['aesKey'],
        OPENSSL_RAW_DATA,
        $flippedIv,
        substr($raw, -16)
    );

    return ['status' => 200, 'data' => json_decode($plaintext, true)];
}

it('answers a health check ping', function () {
    $result = postFlowRequest([
        'version' => '3.0',
        'action' => 'ping',
    ], $this->publicKey);

    expect($result['status'])->toBe(200)
        ->and($result['data'])->toBe(['data' => ['status' => 'active']]);
});

// Meta reports client-side errors on the regular actions, marking them only through
// data.error / data.error_message — there is no dedicated "error" action.
it('acknowledges a client error notification without calling the handler', function (string $action) {
    config()->set('whatsapp.flows.handler', ThrowingFlowHandler::class);

    $result = postFlowRequest([
        'version' => '3.0',
        'flow_token' => 'signup-42',
        'action' => $action,
        'data' => [
            'error' => 'INVALID_ENCRYPTION',
            'error_message' => 'Something went wrong',
        ],
    ], $this->publicKey);

    expect($result['status'])->toBe(200)
        ->and($result['data'])->toBe(['data' => ['acknowledged' => true]]);
})->with(['data_exchange', 'INIT']);

it('does not mistake a legitimate error field in screen data for an error notification', function () {
    config()->set('whatsapp.flows.handler', SignupFlowHandler::class);

    SignupFlowHandler::$received = null;

    postFlowRequest([
        'version' => '3.0',
        'action' => 'data_exchange',
        'screen' => 'SIGNUP',
        'data' => ['email' => 'rodrigo@example.com'],
    ], $this->publicKey);

    expect(SignupFlowHandler::$received)->not->toBeNull();
});

it('routes a data exchange request to the configured handler', function () {
    config()->set('whatsapp.flows.handler', SignupFlowHandler::class);

    SignupFlowHandler::$received = null;

    $result = postFlowRequest([
        'version' => '3.0',
        'action' => 'data_exchange',
        'screen' => 'SIGNUP',
        'flow_token' => 'signup-42',
        'data' => ['email' => 'rodrigo@example.com'],
    ], $this->publicKey);

    expect(SignupFlowHandler::$received?->action)->toBe('data_exchange')
        ->and(SignupFlowHandler::$received?->screen)->toBe('SIGNUP')
        ->and(SignupFlowHandler::$received?->flowToken)->toBe('signup-42')
        ->and(SignupFlowHandler::$received?->data)->toBe(['email' => 'rodrigo@example.com'])
        ->and(SignupFlowHandler::$received?->version)->toBe('3.0');

    expect($result['data'])->toBe([
        'version' => '3.0',
        'screen' => 'CONFIRMATION',
        'data' => ['greeting' => 'Hi rodrigo@example.com'],
    ]);
});

it('returns 421 when the request was encrypted with another key', function () {
    [, $otherPublicKey] = generateFlowKeyPair();

    expect(postFlowRequest(['action' => 'ping'], $otherPublicKey)['status'])->toBe(421);
});

it('returns 421 for a malformed payload', function () {
    $this->postJson('/webhooks/whatsapp/flow', ['nonsense' => true])->assertStatus(421);
});

it('returns 500 when the handler throws', function () {
    config()->set('whatsapp.flows.handler', ThrowingFlowHandler::class);

    expect(postFlowRequest([
        'version' => '3.0',
        'action' => 'data_exchange',
        'screen' => 'SIGNUP',
    ], $this->publicKey)['status'])->toBe(500);
});

it('returns 500 when a data exchange arrives with no handler configured', function () {
    expect(postFlowRequest([
        'version' => '3.0',
        'action' => 'data_exchange',
        'screen' => 'SIGNUP',
    ], $this->publicKey)['status'])->toBe(500);
});

it('returns 404 when the endpoint is disabled', function () {
    config()->set('whatsapp.flows.endpoint_enabled', false);

    $this->postJson('/webhooks/whatsapp/flow', ['nonsense' => true])->assertStatus(404);
});

class SignupFlowHandler implements FlowHandlerInterface
{
    public static ?FlowRequest $received = null;

    public function handle(FlowRequest $request): FlowResponse
    {
        self::$received = $request;

        return FlowResponse::screen('CONFIRMATION', [
            'greeting' => 'Hi '.($request->data['email'] ?? 'there'),
        ]);
    }
}

class ThrowingFlowHandler implements FlowHandlerInterface
{
    public function handle(FlowRequest $request): FlowResponse
    {
        throw new RuntimeException('handler blew up');
    }
}
