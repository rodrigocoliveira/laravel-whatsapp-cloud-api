<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Services;

use Multek\LaravelWhatsAppCloud\Contracts\TranscriptionServiceInterface;
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
     * @throws TranscriptionException
     */
    public function transcribe(string $audioPath, string $language = 'pt-BR'): string
    {
        if (! file_exists($audioPath)) {
            throw TranscriptionException::fileNotFound($audioPath);
        }

        $service = config('whatsapp.transcription.default_service', 'openai');
        $transcriber = $this->getTranscriber($service);

        return $transcriber->transcribe($audioPath, $language);
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
            default => throw TranscriptionException::serviceNotConfigured($service),
        };
    }
}
