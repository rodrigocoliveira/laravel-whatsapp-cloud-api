<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs\Flows;

/**
 * The answer to a data-exchange request: the next screen, or the terminal success.
 */
readonly class FlowResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public ?string $screen,
        public array $data,
        public ?string $version = null,
    ) {}

    /**
     * Move the flow to another screen, optionally seeding it with data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function screen(string $screen, array $data = []): self
    {
        return new self($screen, $data);
    }

    /**
     * Close the flow. The token is echoed back to the client as the flow completes.
     *
     * @param  array<string, mixed>  $data  Extra payload delivered with the completion.
     */
    public static function complete(string $flowToken, array $data = []): self
    {
        return new self('SUCCESS', [
            'extension_message_response' => [
                'params' => array_merge(['flow_token' => $flowToken], $data),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $version): array
    {
        return [
            'version' => $this->version ?? $version,
            'screen' => $this->screen,
            'data' => $this->data,
        ];
    }
}
