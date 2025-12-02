<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Contracts;

use Multek\LaravelWhatsAppCloud\DTOs\TranscriptionResult;

interface TranscriptionServiceInterface
{
    /**
     * Transcribe an audio file to text.
     *
     * Language is auto-detected by the transcription service.
     */
    public function transcribe(string $audioPath): TranscriptionResult;

    /**
     * Check if a mime type is supported for transcription.
     */
    public function supports(string $mimeType): bool;
}
