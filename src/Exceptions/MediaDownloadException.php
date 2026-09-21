<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

class MediaDownloadException extends WhatsAppException
{
    public static function urlExpired(string $mediaId): self
    {
        return new self("Media URL has expired for media ID: {$mediaId}. WhatsApp media URLs are only valid for ~5 minutes.");
    }

    public static function downloadFailed(string $mediaId, string $reason): self
    {
        return new self("Failed to download media {$mediaId}: {$reason}");
    }

    public static function storageFailed(string $reason): self
    {
        return new self("Failed to store media file: {$reason}");
    }

    public static function mediaNotFound(string $mediaId): self
    {
        return new self("Media with ID '{$mediaId}' not found on WhatsApp servers.");
    }

    /**
     * @param  array<string, mixed>|null  $error  Meta's `error` object from the rejected response
     */
    public static function lookupFailed(string $mediaId, int $status, ?array $error = null): self
    {
        $reason = $error['message'] ?? 'no error details returned';

        if (isset($error['code'])) {
            $reason = "[{$error['code']}] {$reason}";
        }

        return new self(
            "Failed to look up media '{$mediaId}' on WhatsApp servers (HTTP {$status}): {$reason}",
            (int) ($error['code'] ?? 0),
            null,
            $error
        );
    }

    public static function fileTooLarge(int $size, int $maxSize): self
    {
        $sizeMb = round($size / 1024 / 1024, 2);
        $maxSizeMb = round($maxSize / 1024 / 1024, 2);

        return new self("Media file size ({$sizeMb}MB) exceeds maximum allowed size ({$maxSizeMb}MB).");
    }
}
