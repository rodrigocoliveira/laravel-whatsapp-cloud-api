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

    public static function rateLimited(string $apiMessage = '', ?array $errorData = null): self
    {
        $message = $apiMessage
            ? "WhatsApp API rate limit exceeded: {$apiMessage}"
            : 'WhatsApp API rate limit exceeded. Please try again later.';

        return new self($message, 4, null, $errorData);
    }

    public static function invalidAccessToken(string $apiMessage, ?array $errorData = null): self
    {
        return new self(
            "Invalid or expired access token: {$apiMessage}. Check your WHATSAPP_ACCESS_TOKEN.",
            190,
            null,
            $errorData
        );
    }

    public static function invalidParameter(string $apiMessage, ?array $errorData = null): self
    {
        return new self(
            "Invalid parameter: {$apiMessage}",
            100,
            null,
            $errorData
        );
    }

    public static function permissionDenied(string $apiMessage, ?array $errorData = null): self
    {
        return new self(
            "Permission denied: {$apiMessage}",
            200,
            null,
            $errorData
        );
    }

    public static function temporarilyBlocked(string $apiMessage, ?array $errorData = null): self
    {
        return new self(
            "Account temporarily blocked: {$apiMessage}",
            368,
            null,
            $errorData
        );
    }
}
