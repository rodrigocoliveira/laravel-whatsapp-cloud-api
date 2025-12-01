<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class SystemContent implements MessageContentInterface
{
    public function __construct(
        public string $body,
        public ?string $identity = null,
        public ?string $newWaId = null,
        public ?string $type = null,
    ) {}

    public function getType(): string
    {
        return 'system';
    }

    public function isIdentityChange(): bool
    {
        return $this->identity !== null;
    }

    public function isNumberChange(): bool
    {
        return $this->newWaId !== null;
    }

    public function toArray(): array
    {
        return array_filter([
            'body' => $this->body,
            'identity' => $this->identity,
            'new_wa_id' => $this->newWaId,
            'type' => $this->type,
        ], fn ($value) => $value !== null);
    }
}
