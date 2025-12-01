<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class InteractiveReplyContent implements MessageContentInterface
{
    public function __construct(
        public string $replyType,
        public string $id,
        public string $title,
        public ?string $description = null,
    ) {}

    public function getType(): string
    {
        return 'interactive';
    }

    public function isButtonReply(): bool
    {
        return $this->replyType === 'button_reply';
    }

    public function isListReply(): bool
    {
        return $this->replyType === 'list_reply';
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->replyType,
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
        ], fn ($value) => $value !== null);
    }
}
