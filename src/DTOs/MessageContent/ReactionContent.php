<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class ReactionContent implements MessageContentInterface
{
    public function __construct(
        public string $messageId,
        public string $emoji,
    ) {}

    public function getType(): string
    {
        return 'reaction';
    }

    public function isRemoval(): bool
    {
        return $this->emoji === '';
    }

    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'emoji' => $this->emoji,
        ];
    }
}
