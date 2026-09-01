<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

/**
 * Raised whenever a Flow data-exchange payload cannot be decrypted or encrypted.
 *
 * Meta refreshes the business public key when the endpoint answers 421, so the
 * endpoint maps this exception to that status.
 */
class FlowEncryptionException extends WhatsAppException
{
    public static function invalidPrivateKey(): self
    {
        return new self('The configured WhatsApp Flow private key could not be read.');
    }

    public static function malformedPayload(): self
    {
        return new self('The Flow request is missing the encrypted payload fields.');
    }

    public static function keyDecryptionFailed(): self
    {
        return new self('The Flow AES key could not be decrypted with the business private key.');
    }

    public static function bodyDecryptionFailed(): self
    {
        return new self('The Flow request body could not be decrypted.');
    }

    public static function encryptionFailed(): self
    {
        return new self('The Flow response could not be encrypted.');
    }
}
