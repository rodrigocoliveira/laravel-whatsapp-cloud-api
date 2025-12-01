<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class UnknownContent implements MessageContentInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $type,
        public array $data = [],
    ) {}

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'data' => $this->data,
        ];
    }
}
