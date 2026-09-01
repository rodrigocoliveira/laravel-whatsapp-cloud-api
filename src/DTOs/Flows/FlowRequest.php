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

    /**
     * Client-side errors arrive on the regular actions (`data_exchange` or `INIT`)
     * and are identified by the error fields in the payload, not by the action.
     */
    public function isErrorNotification(): bool
    {
        return isset($this->data['error_message']) || isset($this->data['error']);
    }

    /**
     * The error reported by the client, when this is an error notification.
     */
    public function errorMessage(): ?string
    {
        $message = $this->data['error_message'] ?? $this->data['error'] ?? null;

        return is_string($message) ? $message : null;
    }
}
