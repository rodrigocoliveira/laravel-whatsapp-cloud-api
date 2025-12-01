<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

interface MessageContentInterface
{
    public function getType(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
