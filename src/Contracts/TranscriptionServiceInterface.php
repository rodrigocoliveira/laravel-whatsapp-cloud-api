<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Contracts;

interface TranscriptionServiceInterface
{
    /**
     * Transcribe an audio file to text.
     */
    public function transcribe(string $audioPath, string $language = 'pt-BR'): string;

    /**
     * Check if a mime type is supported for transcription.
     */
    public function supports(string $mimeType): bool;
}
