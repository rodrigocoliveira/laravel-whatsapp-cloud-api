<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Exceptions\FlowEncryptionException;
use Multek\LaravelWhatsAppCloud\Services\Flows\FlowEncryptionService;

beforeEach(function () {
    [$this->privateKey, $this->publicKey] = generateFlowKeyPair();
    $this->service = new FlowEncryptionService($this->privateKey);
});

it('decrypts a request encrypted the way meta encrypts it', function () {
    $encrypted = encryptFlowRequest([
        'version' => '3.0',
        'action' => 'data_exchange',
        'screen' => 'SIGNUP',
        'flow_token' => 'signup-42',
        'data' => ['name' => 'Rodrigo'],
    ], $this->publicKey);

    $decrypted = $this->service->decrypt($encrypted['payload']);

    expect($decrypted->body)->toBe([
        'version' => '3.0',
        'action' => 'data_exchange',
        'screen' => 'SIGNUP',
        'flow_token' => 'signup-42',
        'data' => ['name' => 'Rodrigo'],
    ])
        ->and($decrypted->aesKey)->toBe($encrypted['aesKey'])
        ->and($decrypted->initialVector)->toBe($encrypted['iv']);
});

it('encrypts the response with the flipped initial vector', function () {
    $encrypted = encryptFlowRequest(['action' => 'ping'], $this->publicKey);
    $decrypted = $this->service->decrypt($encrypted['payload']);

    $response = $this->service->encrypt(
        ['data' => ['status' => 'active']],
        $decrypted
    );

    $raw = base64_decode($response);
    $flippedIv = $encrypted['iv'] ^ str_repeat("\xff", strlen($encrypted['iv']));

    $plaintext = openssl_decrypt(
        substr($raw, 0, -16),
        'aes-128-gcm',
        $encrypted['aesKey'],
        OPENSSL_RAW_DATA,
        $flippedIv,
        substr($raw, -16)
    );

    expect(json_decode($plaintext, true))->toBe(['data' => ['status' => 'active']]);
});

it('rejects a request encrypted with a different key', function () {
    [, $otherPublicKey] = generateFlowKeyPair();

    $encrypted = encryptFlowRequest(['action' => 'ping'], $otherPublicKey);

    expect(fn () => $this->service->decrypt($encrypted['payload']))
        ->toThrow(FlowEncryptionException::class);
});

it('rejects a tampered ciphertext', function () {
    $encrypted = encryptFlowRequest(['action' => 'ping'], $this->publicKey);

    $raw = base64_decode($encrypted['payload']['encrypted_flow_data']);
    $raw[0] = $raw[0] === "\x00" ? "\x01" : "\x00";
    $encrypted['payload']['encrypted_flow_data'] = base64_encode($raw);

    expect(fn () => $this->service->decrypt($encrypted['payload']))
        ->toThrow(FlowEncryptionException::class);
});

it('rejects a payload with missing fields', function () {
    expect(fn () => $this->service->decrypt(['encrypted_aes_key' => 'x']))
        ->toThrow(FlowEncryptionException::class);
});

it('rejects an unusable private key', function () {
    expect(fn () => new FlowEncryptionService('not-a-key'))
        ->toThrow(FlowEncryptionException::class);
});

it('supports aes-256 keys', function () {
    $encrypted = encryptFlowRequest(['action' => 'ping'], $this->publicKey, random_bytes(32));

    expect($this->service->decrypt($encrypted['payload'])->body)->toBe(['action' => 'ping']);
});
