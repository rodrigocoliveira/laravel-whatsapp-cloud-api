<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class DocumentContent implements MessageContentInterface
{
    public function __construct(
        public string $mediaId,
        public ?string $filename = null,
        public ?string $caption = null,
        public ?string $mimeType = null,
        public ?string $sha256 = null,
    ) {}

    public function getType(): string
    {
        return 'document';
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->mediaId,
            'filename' => $this->filename,
            'caption' => $this->caption,
            'mime_type' => $this->mimeType,
            'sha256' => $this->sha256,
        ], fn ($value) => $value !== null);
    }
}
