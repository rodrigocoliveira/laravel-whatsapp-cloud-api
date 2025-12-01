<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class AudioContent implements MessageContentInterface
{
    public function __construct(
        public string $mediaId,
        public ?string $mimeType = null,
        public bool $voice = false,
    ) {}

    public function getType(): string
    {
        return 'audio';
    }

    public function isVoiceNote(): bool
    {
        return $this->voice;
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->mediaId,
            'mime_type' => $this->mimeType,
            'voice' => $this->voice,
        ], fn ($value) => $value !== null && $value !== false);
    }
}
