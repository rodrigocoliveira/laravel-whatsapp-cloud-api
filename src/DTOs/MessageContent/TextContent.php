<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class TextContent implements MessageContentInterface
{
    public function __construct(
        public string $body,
    ) {}

    public function getType(): string
    {
        return 'text';
    }

    public function toArray(): array
    {
        return [
            'body' => $this->body,
        ];
    }
}
