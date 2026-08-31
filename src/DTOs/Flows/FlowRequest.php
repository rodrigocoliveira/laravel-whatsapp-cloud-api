<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\Flows;

/**
 * A decrypted data-exchange request from a WhatsApp Flow.
 */
readonly class FlowRequest
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $action,
        public ?string $screen = null,
        public array $data = [],
        public ?string $flowToken = null,
        public ?string $version = null,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            action: is_string($body['action'] ?? null) ? $body['action'] : '',
            screen: is_string($body['screen'] ?? null) ? $body['screen'] : null,
            data: is_array($body['data'] ?? null) ? $body['data'] : [],
            flowToken: is_string($body['flow_token'] ?? null) ? $body['flow_token'] : null,
            version: is_string($body['version'] ?? null) ? $body['version'] : null,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function isPing(): bool
    {
        return $this->action === 'ping';
    }

    public function isErrorNotification(): bool
    {
        return $this->action === 'error';
    }
}
