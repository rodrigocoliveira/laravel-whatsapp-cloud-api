<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs;

/**
 * Result of an audio transcription.
 */
readonly class TranscriptionResult
{
    public function __construct(
        public string $text,
        public ?string $detectedLanguage = null,
        public ?float $duration = null,
    ) {}

    /**
     * Create from array data.
     *
     * @param  array{text: string, language?: string|null, duration?: float|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: $data['text'],
            detectedLanguage: $data['language'] ?? null,
            duration: $data['duration'] ?? null,
        );
    }

    /**
     * Convert to array.
     *
     * @return array{text: string, detected_language: string|null, duration: float|null}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'detected_language' => $this->detectedLanguage,
            'duration' => $this->duration,
        ];
    }
}
