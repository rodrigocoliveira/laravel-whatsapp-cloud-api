<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class LocationContent implements MessageContentInterface
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $name = null,
        public ?string $address = null,
    ) {}

    public function getType(): string
    {
        return 'location';
    }

    public function toArray(): array
    {
        return array_filter([
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'name' => $this->name,
            'address' => $this->address,
        ], fn ($value) => $value !== null);
    }
}
