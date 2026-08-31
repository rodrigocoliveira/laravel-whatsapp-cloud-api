<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

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

it('generates a usable key pair', function () {
    $this->artisan('whatsapp:flow-key generate')
        ->expectsOutputToContain('BEGIN PRIVATE KEY')
        ->assertSuccessful();
});

it('uploads the public key derived from the configured private key', function () {
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privateKey);
    $expectedPublicKey = openssl_pkey_get_details($resource)['key'];

    config()->set('whatsapp.flows.private_key', $privateKey);

    $this->artisan('whatsapp:flow-key upload --phone=test')->assertSuccessful();

    Http::assertSent(function ($request) use ($expectedPublicKey) {
        expect($request->url())->toContain('/test_phone_id/whatsapp_business_encryption')
            ->and($request->data()['business_public_key'])->toBe($expectedPublicKey);

        return true;
    });
});

it('fails to upload when no private key is configured', function () {
    config()->set('whatsapp.flows.private_key', null);

    $this->artisan('whatsapp:flow-key upload --phone=test')->assertFailed();
});

it('fails to upload for an unknown phone', function () {
    config()->set('whatsapp.flows.private_key', 'irrelevant');

    $this->artisan('whatsapp:flow-key upload --phone=nope')->assertFailed();
});

it('reports an upload rejected by meta', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid key']], 400)]);

    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privateKey);
    config()->set('whatsapp.flows.private_key', $privateKey);

    $this->artisan('whatsapp:flow-key upload --phone=test')->assertFailed();
});
