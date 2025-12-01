<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class OrderContent implements MessageContentInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $productItems
     */
    public function __construct(
        public string $catalogId,
        public array $productItems,
        public ?string $text = null,
    ) {}

    public function getType(): string
    {
        return 'order';
    }

    public function getItemCount(): int
    {
        return count($this->productItems);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProductItems(): array
    {
        return $this->productItems;
    }

    public function toArray(): array
    {
        return array_filter([
            'catalog_id' => $this->catalogId,
            'product_items' => $this->productItems,
            'text' => $this->text,
        ], fn ($value) => $value !== null);
    }
}
