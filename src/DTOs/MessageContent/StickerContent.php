<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class StickerContent implements MessageContentInterface
{
    public function __construct(
        public string $mediaId,
        public ?string $mimeType = null,
        public bool $animated = false,
    ) {}

    public function getType(): string
    {
        return 'sticker';
    }

    public function isAnimated(): bool
    {
        return $this->animated;
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->mediaId,
            'mime_type' => $this->mimeType,
            'animated' => $this->animated,
        ], fn ($value) => $value !== null && $value !== false);
    }
}
