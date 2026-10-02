<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\Flows;

use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppFlow;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppFlowSession;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

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
        public ?WhatsAppFlowSession $session = null,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body, ?WhatsAppFlowSession $session = null): self
    {
        return new self(
            action: is_string($body['action'] ?? null) ? $body['action'] : '',
            screen: is_string($body['screen'] ?? null) ? $body['screen'] : null,
            data: is_array($body['data'] ?? null) ? $body['data'] : [],
            flowToken: is_string($body['flow_token'] ?? null) ? $body['flow_token'] : null,
            version: is_string($body['version'] ?? null) ? $body['version'] : null,
            session: $session,
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

    /** The flow was opened. */
    public function isInit(): bool
    {
        return $this->action === 'INIT';
    }

    /** The user pressed back. */
    public function isBack(): bool
    {
        return $this->action === 'BACK';
    }

    /** A screen was submitted. */
    public function isDataExchange(): bool
    {
        return $this->action === 'data_exchange';
    }

    /**
     * The session recorded when this flow was sent. Null for tokens the package did not
     * send, e.g. flows sent before sessions existed or through the raw client.
     */
    public function session(): ?WhatsAppFlowSession
    {
        return $this->session;
    }

    /** The synced flow, when it was sent through a known one. */
    public function flow(): ?WhatsAppFlow
    {
        return $this->session?->flow;
    }

    public function conversation(): ?WhatsAppConversation
    {
        return $this->session?->message->conversation;
    }

    public function phone(): ?WhatsAppPhone
    {
        return $this->session?->message->phone;
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
