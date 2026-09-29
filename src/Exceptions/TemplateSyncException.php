<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

class TemplateSyncException extends WhatsAppException
{
    /**
     * @param  array<string, mixed>|null  $error  Meta's `error` object from the rejected response
     */
    public static function fetchFailed(int $status, ?array $error = null): self
    {
        $reason = $error['message'] ?? 'no error details returned';

        if (isset($error['code'])) {
            $reason = "[{$error['code']}] {$reason}";
        }

        return new self(
            "Failed to fetch message templates from WhatsApp (HTTP {$status}): {$reason}",
            (int) ($error['code'] ?? 0),
            null,
            $error
        );
    }

    /**
     * A successful response whose body cannot be trusted as a template page.
     */
    public static function malformedPage(string $reason): self
    {
        return new self("Received a malformed message template page from WhatsApp: {$reason}");
    }
}
