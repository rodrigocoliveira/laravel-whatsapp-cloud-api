<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

class FlowSyncException extends WhatsAppException
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
            "Failed to fetch flows from WhatsApp (HTTP {$status}): {$reason}",
            (int) ($error['code'] ?? 0),
            null,
            $error
        );
    }

    public static function malformedPage(string $reason): self
    {
        return new self("Received a malformed flow page from WhatsApp: {$reason}");
    }
}
