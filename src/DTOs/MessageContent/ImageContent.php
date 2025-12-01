<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class ImageContent implements MessageContentInterface
{
    public function __construct(
        public string $mediaId,
        public ?string $caption = null,
        public ?string $mimeType = null,
        public ?string $sha256 = null,
    ) {}

    public function getType(): string
    {
        return 'image';
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->mediaId,
            'caption' => $this->caption,
            'mime_type' => $this->mimeType,
            'sha256' => $this->sha256,
        ], fn ($value) => $value !== null);
    }
}
