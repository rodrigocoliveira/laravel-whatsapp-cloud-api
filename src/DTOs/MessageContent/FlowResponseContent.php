<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

/**
 * Submitted data from a WhatsApp Flow (the `nfm_reply` interactive payload).
 */
readonly class FlowResponseContent implements MessageContentInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public ?string $responseJson = null,
        public ?string $body = null,
        public ?string $name = null,
        public ?string $flowToken = null,
    ) {}

    public function getType(): string
    {
        return 'nfm_reply';
    }

    /**
     * Get a single submitted field.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => 'nfm_reply',
            'name' => $this->name,
            'body' => $this->body,
            'flow_token' => $this->flowToken,
            'response_json' => $this->responseJson,
            'data' => $this->data,
        ], fn ($value) => $value !== null);
    }
}
