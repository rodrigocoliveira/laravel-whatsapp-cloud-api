<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

class TranscriptionException extends WhatsAppException
{
    public static function serviceNotConfigured(string $service): self
    {
        return new self("Transcription service '{$service}' is not configured properly.");
    }

    public static function unsupportedMimeType(string $mimeType): self
    {
        return new self("MIME type '{$mimeType}' is not supported for transcription.");
    }

    public static function transcriptionFailed(string $reason): self
    {
        return new self("Transcription failed: {$reason}");
    }

    public static function fileNotFound(string $path): self
    {
        return new self("Audio file not found at path: {$path}");
    }

    public static function serviceUnavailable(string $service): self
    {
        return new self("Transcription service '{$service}' is currently unavailable.");
    }
}
