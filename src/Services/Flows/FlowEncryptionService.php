<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Services\Flows;

use Multek\LaravelWhatsAppCloud\Exceptions\FlowEncryptionException;

/**
 * The crypto handshake for WhatsApp Flow data-exchange requests.
 *
 * Meta encrypts a one-off AES key with the business public key (RSA-OAEP, SHA-256
 * for both the digest and MGF1) and the request body with AES-GCM. The response
 * must reuse the same AES key with a bitwise-flipped initial vector.
 *
 * ext-openssl cannot pick the OAEP digest, so the RSA step decrypts without padding
 * and this class performs the EME-OAEP decoding itself.
 */
class FlowEncryptionService
{
    private const HASH = 'sha256';

    private const TAG_LENGTH = 16;

    /** @var \OpenSSLAsymmetricKey */
    private $privateKey;

    public function __construct(string $privateKeyPem, ?string $passphrase = null)
    {
        $key = openssl_pkey_get_private($privateKeyPem, $passphrase);

        if ($key === false) {
            throw FlowEncryptionException::invalidPrivateKey();
        }

        $this->privateKey = $key;
    }

    /**
     * Decrypt an incoming data-exchange request.
     *
     * @param  array<string, mixed>  $payload  The raw request body from Meta.
     */
    public function decrypt(array $payload): DecryptedFlowRequest
    {
        $encryptedBody = $payload['encrypted_flow_data'] ?? null;
        $encryptedKey = $payload['encrypted_aes_key'] ?? null;
        $initialVector = $payload['initial_vector'] ?? null;

        if (! is_string($encryptedBody) || ! is_string($encryptedKey) || ! is_string($initialVector)) {
            throw FlowEncryptionException::malformedPayload();
        }

        $encryptedBody = base64_decode($encryptedBody, true);
        $encryptedKey = base64_decode($encryptedKey, true);
        $initialVector = base64_decode($initialVector, true);

        if ($encryptedBody === false || $encryptedKey === false || $initialVector === false) {
            throw FlowEncryptionException::malformedPayload();
        }

        $aesKey = $this->decryptAesKey($encryptedKey);

        $ciphertext = substr($encryptedBody, 0, -self::TAG_LENGTH);
        $tag = substr($encryptedBody, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $this->cipherFor($aesKey),
            $aesKey,
            OPENSSL_RAW_DATA,
            $initialVector,
            $tag
        );

        if ($plaintext === false) {
            throw FlowEncryptionException::bodyDecryptionFailed();
        }

        $body = json_decode($plaintext, true);

        if (! is_array($body)) {
            throw FlowEncryptionException::bodyDecryptionFailed();
        }

        return new DecryptedFlowRequest(
            body: $body,
            aesKey: $aesKey,
            initialVector: $initialVector,
        );
    }

    /**
     * Encrypt a response for the request it answers.
     *
     * @param  array<string, mixed>  $response
     * @return string Base64 of the ciphertext with the auth tag appended.
     */
    public function encrypt(array $response, DecryptedFlowRequest $request): string
    {
        $flippedVector = $request->initialVector ^ str_repeat("\xff", strlen($request->initialVector));

        $ciphertext = openssl_encrypt(
            (string) json_encode($response),
            $this->cipherFor($request->aesKey),
            $request->aesKey,
            OPENSSL_RAW_DATA,
            $flippedVector,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw FlowEncryptionException::encryptionFailed();
        }

        return base64_encode($ciphertext.$tag);
    }

    /**
     * RSA-OAEP(SHA-256) decrypt, decoding the padding by hand.
     */
    private function decryptAesKey(string $encryptedKey): string
    {
        if (! openssl_private_decrypt($encryptedKey, $encoded, $this->privateKey, OPENSSL_NO_PADDING)) {
            throw FlowEncryptionException::keyDecryptionFailed();
        }

        return $this->oaepDecode($encoded);
    }

    /**
     * EME-OAEP decoding with an empty label (RFC 8017, section 7.1.2).
     */
    private function oaepDecode(string $encoded): string
    {
        $labelHash = hash(self::HASH, '', true);
        $hashLength = strlen($labelHash);

        if (strlen($encoded) < 2 * $hashLength + 2 || $encoded[0] !== "\x00") {
            throw FlowEncryptionException::keyDecryptionFailed();
        }

        $maskedSeed = substr($encoded, 1, $hashLength);
        $maskedBlock = substr($encoded, 1 + $hashLength);

        $seed = $maskedSeed ^ $this->mgf1($maskedBlock, $hashLength);
        $block = $maskedBlock ^ $this->mgf1($seed, strlen($maskedBlock));

        if (! hash_equals($labelHash, substr($block, 0, $hashLength))) {
            throw FlowEncryptionException::keyDecryptionFailed();
        }

        $padded = substr($block, $hashLength);
        $separator = strpos($padded, "\x01");

        if ($separator === false || strspn($padded, "\x00", 0, $separator) !== $separator) {
            throw FlowEncryptionException::keyDecryptionFailed();
        }

        return substr($padded, $separator + 1);
    }

    /**
     * MGF1 mask generation (RFC 8017, appendix B.2.1).
     */
    private function mgf1(string $seed, int $length): string
    {
        $mask = '';

        for ($counter = 0; strlen($mask) < $length; $counter++) {
            $mask .= hash(self::HASH, $seed.pack('N', $counter), true);
        }

        return substr($mask, 0, $length);
    }

    private function cipherFor(string $aesKey): string
    {
        return 'aes-'.(strlen($aesKey) * 8).'-gcm';
    }
}
