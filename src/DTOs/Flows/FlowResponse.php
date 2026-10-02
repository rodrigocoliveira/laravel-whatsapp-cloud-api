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
     * Stay on (or move to) a screen and show an error message there.
     *
     * The flow JSON reads it from `${data.error_message}`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function error(string $screen, string $message, array $data = []): self
    {
        return new self($screen, ['error_message' => $message] + $data);
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

    public function isComplete(): bool
    {
        return $this->screen === 'SUCCESS';
    }

    /**
     * The params delivered with the completion, without the echoed flow token.
     *
     * @return array<string, mixed>
     */
    public function completionParams(): array
    {
        $params = $this->data['extension_message_response']['params'] ?? [];
        unset($params['flow_token']);

        return $params;
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
