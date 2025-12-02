<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Services;

use Multek\LaravelWhatsAppCloud\Contracts\TranscriptionServiceInterface;
use Multek\LaravelWhatsAppCloud\DTOs\TranscriptionResult;
use Multek\LaravelWhatsAppCloud\Exceptions\TranscriptionException;
use Multek\LaravelWhatsAppCloud\Services\Transcription\OpenAITranscriber;

class TranscriptionService implements TranscriptionServiceInterface
{
    protected const SUPPORTED_MIME_TYPES = [
        'audio/aac',
        'audio/mp4',
        'audio/m4a',
        'audio/mpeg',
        'audio/mp3',
        'audio/amr',
        'audio/ogg',
        'audio/opus',
        'audio/webm',
        'audio/wav',
        'audio/x-wav',
    ];

    /**
     * Transcribe an audio file to text.
     *
     * Language is auto-detected by the transcription service.
     *
     * @throws TranscriptionException
     */
    public function transcribe(string $audioPath): TranscriptionResult
    {
        if (! file_exists($audioPath)) {
            throw TranscriptionException::fileNotFound($audioPath);
        }

        $service = config('whatsapp.transcription.default_service', 'openai');
        $transcriber = $this->getTranscriber($service);

        return $transcriber->transcribe($audioPath);
    }

    /**
     * Check if a mime type is supported for transcription.
     */
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    /**
     * Get the transcriber instance for a service.
     *
     * @throws TranscriptionException
     */
    protected function getTranscriber(string $service): TranscriptionServiceInterface
    {
        return match ($service) {
            'openai' => new OpenAITranscriber,
            'custom' => $this->resolveCustomTranscriber(),
            default => throw TranscriptionException::serviceNotConfigured($service),
        };
    }

    /**
     * Resolve a custom transcriber from the container.
     *
     * @throws TranscriptionException
     */
    protected function resolveCustomTranscriber(): TranscriptionServiceInterface
    {
        $class = config('whatsapp.transcription.services.custom.class');

        if (! $class) {
            throw TranscriptionException::serviceNotConfigured(
                'custom - No custom transcription class configured. Set WHATSAPP_TRANSCRIPTION_CLASS or whatsapp.transcription.services.custom.class'
            );
        }

        if (! class_exists($class)) {
            throw TranscriptionException::serviceNotConfigured(
                "custom - Class {$class} does not exist"
            );
        }

        $instance = app($class);

        if (! $instance instanceof TranscriptionServiceInterface) {
            throw TranscriptionException::serviceNotConfigured(
                "custom - Class {$class} must implement TranscriptionServiceInterface"
            );
        }

        return $instance;
    }
}
