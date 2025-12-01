<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

class MessageSendException extends WhatsAppException
{
    public static function apiError(string $message, ?array $errorData = null): self
    {
        return new self("Failed to send WhatsApp message: {$message}", 0, null, $errorData);
    }

    public static function invalidRecipient(string $phone): self
    {
        return new self("Invalid recipient phone number: {$phone}");
    }

    public static function templateNotFound(string $templateName): self
    {
        return new self("Template '{$templateName}' not found or not approved.");
    }

    public static function mediaUploadFailed(string $reason): self
    {
        return new self("Failed to upload media: {$reason}");
    }

    public static function rateLimited(): self
    {
        return new self('WhatsApp API rate limit exceeded. Please try again later.');
    }
}
