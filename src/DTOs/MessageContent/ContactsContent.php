<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

readonly class ContactsContent implements MessageContentInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function __construct(
        public array $contacts,
    ) {}

    public function getType(): string
    {
        return 'contacts';
    }

    public function getCount(): int
    {
        return count($this->contacts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getContacts(): array
    {
        return $this->contacts;
    }

    public function toArray(): array
    {
        return [
            'contacts' => $this->contacts,
        ];
    }
}
