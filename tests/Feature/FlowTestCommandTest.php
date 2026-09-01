<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Services\Flows\FlowEncryptionService;

beforeEach(function () {
    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'access_token' => 'phone_token',
        'is_active' => true,
    ]);
});

it('sends a real flow message and reports the wamid', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.smoke']]], 200)]);

    $this->artisan('whatsapp:flow-test send --phone=test --flow-id=999 --to=5511999999999')
        ->expectsOutputToContain('wamid.smoke')
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        $parameters = $request->data()['interactive']['action']['parameters'];

        expect($parameters['flow_id'])->toBe('999')
            ->and($parameters['mode'])->toBe('draft');

        return true;
    });

    expect(WhatsAppMessage::where('message_id', 'wamid.smoke')->exists())->toBeTrue();
});

it('reports the graph api error when meta rejects the flow message', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Flow not found', 'code' => 100]], 400)]);

    $this->artisan('whatsapp:flow-test send --phone=test --flow-id=999 --to=5511999999999')
        ->expectsOutputToContain('Flow not found')
        ->assertFailed();
});

it('pings the configured endpoint with a properly encrypted payload', function () {
    [$privateKey, $publicKey] = generateFlowKeyPair();

    config()->set('whatsapp.flows.private_key', $privateKey);
    config()->set('whatsapp.flows.endpoint_enabled', true);
    config()->set('app.url', 'https://app.test');

    // Answer the way our own endpoint does: the ping response, encrypted with the
    // AES key the command generated and the flipped initial vector.
    Http::fake(function ($request) {
        $service = new FlowEncryptionService(
            config('whatsapp.flows.private_key')
        );

        $decrypted = $service->decrypt($request->data());

        expect($decrypted->body)->toBe(['version' => '3.0', 'action' => 'ping']);

        return Http::response($service->encrypt(['data' => ['status' => 'active']], $decrypted), 200);
    });

    $this->artisan('whatsapp:flow-test ping')
        ->expectsOutputToContain('active')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://app.test/webhooks/whatsapp/flow');
});

it('fails the ping when the endpoint answers 421', function () {
    [$privateKey] = generateFlowKeyPair();

    config()->set('whatsapp.flows.private_key', $privateKey);
    config()->set('app.url', 'https://app.test');

    Http::fake(['*' => Http::response('', 421)]);

    $this->artisan('whatsapp:flow-test ping')
        ->expectsOutputToContain('421')
        ->assertFailed();
});

it('fails the ping when no private key is configured', function () {
    config()->set('whatsapp.flows.private_key', null);

    $this->artisan('whatsapp:flow-test ping')->assertFailed();
});

it('rejects an unknown action', function () {
    $this->artisan('whatsapp:flow-test nonsense')->assertFailed();
});
