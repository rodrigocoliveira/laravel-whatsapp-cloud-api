<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Support;

use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

class TemplateBuilder
{
    protected string $templateName;

    protected string $language = 'pt_BR';

    /** @var array<int, array<string, mixed>> */
    protected array $components = [];

    protected ?string $headerType = null;

    /** @var array<string, mixed>|null */
    protected ?array $headerValue = null;

    /** @var array<int, string> */
    protected array $bodyParameters = [];

    /** @var array<int, array<string, mixed>> */
    protected array $buttonParameters = [];

    public function __construct(
        protected WhatsAppPhone $phone,
        protected WhatsAppClientInterface $client,
        protected string $to,
    ) {}

    /**
     * Set the template name.
     */
    public function name(string $name): self
    {
        $this->templateName = $name;

        return $this;
    }

    /**
     * Set the template language.
     */
    public function language(string $code): self
    {
        $this->language = $code;

        return $this;
    }

    /**
     * Set header as text.
     */
    public function headerText(string $text): self
    {
        $this->headerType = 'text';
        $this->headerValue = ['type' => 'text', 'text' => $text];

        return $this;
    }

    /**
     * Set header as image.
     */
    public function headerImage(string $url): self
    {
        $this->headerType = 'image';
        $this->headerValue = ['type' => 'image', 'image' => ['link' => $url]];

        return $this;
    }

    /**
     * Set header as video.
     */
    public function headerVideo(string $url): self
    {
        $this->headerType = 'video';
        $this->headerValue = ['type' => 'video', 'video' => ['link' => $url]];

        return $this;
    }

    /**
     * Set header as document.
     */
    public function headerDocument(string $url, ?string $filename = null): self
    {
        $this->headerType = 'document';
        $documentPayload = ['link' => $url];
        if ($filename) {
            $documentPayload['filename'] = $filename;
        }
        $this->headerValue = ['type' => 'document', 'document' => $documentPayload];

        return $this;
    }

    /**
     * Set body parameters.
     *
     * @param  array<int, string>  $parameters
     */
    public function bodyParameters(array $parameters): self
    {
        $this->bodyParameters = $parameters;

        return $this;
    }

    /**
     * Add a body parameter.
     */
    public function addBodyParameter(string $value): self
    {
        $this->bodyParameters[] = $value;

        return $this;
    }

    /**
     * Set button parameters (for URL buttons with dynamic suffix).
     *
     * @param  array<int, string>  $parameters
     */
    public function buttonParameters(array $parameters): self
    {
        foreach ($parameters as $index => $value) {
            $this->buttonParameters[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => $index,
                'parameters' => [['type' => 'text', 'text' => $value]],
            ];
        }

        return $this;
    }

    /**
     * Add a quick reply button parameter.
     */
    public function addQuickReplyButton(int $index, string $payload): self
    {
        $this->buttonParameters[] = [
            'type' => 'button',
            'sub_type' => 'quick_reply',
            'index' => $index,
            'parameters' => [['type' => 'payload', 'payload' => $payload]],
        ];

        return $this;
    }

    /**
     * Build the components array for the API.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildComponents(): array
    {
        $components = [];

        // Header component
        if ($this->headerValue !== null) {
            $components[] = [
                'type' => 'header',
                'parameters' => [$this->headerValue],
            ];
        }

        // Body component
        if (! empty($this->bodyParameters)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($param) => ['type' => 'text', 'text' => $param],
                    $this->bodyParameters
                ),
            ];
        }

        // Button components
        foreach ($this->buttonParameters as $button) {
            $components[] = $button;
        }

        return $components;
    }

    /**
     * Send the template message.
     */
    public function send(): WhatsAppMessage
    {
        $components = $this->buildComponents();

        $result = $this->client->sendTemplate(
            $this->to,
            $this->templateName,
            $components,
            $this->language
        );

        $messageId = $result['messages'][0]['id'] ?? 'unknown_'.uniqid();

        return WhatsAppMessage::create([
            'whatsapp_phone_id' => $this->phone->id,
            'message_id' => $messageId,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'template',
            'from' => $this->phone->phone_number,
            'to' => $this->to,
            'content' => [
                'template' => [
                    'name' => $this->templateName,
                    'language' => $this->language,
                    'components' => $components,
                ],
            ],
            'status' => WhatsAppMessage::STATUS_PROCESSED,
            'delivery_status' => WhatsAppMessage::DELIVERY_STATUS_SENT,
            'sent_at' => now(),
            'template_name' => $this->templateName,
            'template_parameters' => [
                'header' => $this->headerValue,
                'body' => $this->bodyParameters,
                'buttons' => $this->buttonParameters,
            ],
        ]);
    }
}
