<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Encrypt a request the way Meta does: RSA-OAEP(SHA-256) for the AES key,
 * AES-GCM for the body, with the tag appended to the ciphertext.
 *
 * @return array{payload: array<string, string>, aesKey: string, iv: string}
 */
function encryptFlowRequest(array $body, string $publicKeyPem, ?string $aesKey = null): array
{
    $aesKey ??= random_bytes(16);
    $iv = random_bytes(16);

    $keyFile = tempnam(sys_get_temp_dir(), 'pub');
    file_put_contents($keyFile, $publicKeyPem);
    $inFile = tempnam(sys_get_temp_dir(), 'aes');
    file_put_contents($inFile, $aesKey);
    $outFile = tempnam(sys_get_temp_dir(), 'enc');

    exec(sprintf(
        'openssl pkeyutl -encrypt -pubin -inkey %s -in %s -out %s '.
        '-pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256 2>&1',
        escapeshellarg($keyFile),
        escapeshellarg($inFile),
        escapeshellarg($outFile)
    ), $output, $status);

    expect($status)->toBe(0, 'openssl CLI failed: '.implode("\n", $output));

    $encryptedKey = file_get_contents($outFile);
    @unlink($keyFile);
    @unlink($inFile);
    @unlink($outFile);

    $ciphertext = openssl_encrypt(
        json_encode($body),
        'aes-'.(strlen($aesKey) * 8).'-gcm',
        $aesKey,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    return [
        'payload' => [
            'encrypted_flow_data' => base64_encode($ciphertext.$tag),
            'encrypted_aes_key' => base64_encode($encryptedKey),
            'initial_vector' => base64_encode($iv),
        ],
        'aesKey' => $aesKey,
        'iv' => $iv,
    ];
}

function generateFlowKeyPair(): array
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($resource, $privateKey);

    return [$privateKey, openssl_pkey_get_details($resource)['key']];
}

/**
 * Post an encrypted request to the flow endpoint and return the decrypted response.
 *
 * @param  array<string, mixed>  $body
 * @return array{status: int, data: array<string, mixed>|null}
 */
function postFlowRequest(array $body, string $publicKey): array
{
    $encrypted = encryptFlowRequest($body, $publicKey);

    $response = test()->withHeader('X-Hub-Signature-256', test()->generateSignature($encrypted['payload']))
        ->postJson('/webhooks/whatsapp/flow', $encrypted['payload']);

    if ($response->getStatusCode() !== 200) {
        // Error answers (e.g. 427's error_msg) are plain JSON, not encrypted.
        return ['status' => $response->getStatusCode(), 'data' => json_decode((string) $response->getContent(), true)];
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
