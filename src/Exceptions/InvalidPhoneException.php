<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

class InvalidPhoneException extends WhatsAppException
{
    public static function notFound(string $key): self
    {
        return new self("WhatsApp phone with key '{$key}' not found.");
    }

    public static function inactive(string $key): self
    {
        return new self("WhatsApp phone with key '{$key}' is not active.");
    }

    public static function noPhoneConfigured(): self
    {
        return new self('No WhatsApp phone has been configured. Create a phone in the database first.');
    }

    public static function phoneIdNotFound(string $phoneId): self
    {
        return new self("WhatsApp phone with phone_id '{$phoneId}' not found.");
    }
}
