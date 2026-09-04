<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Support;

use Illuminate\Support\Str;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Events\MessageSent;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSendMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

class MessageBuilder
{
    protected ?string $to = null;

    protected ?WhatsAppConversation $conversation = null;

    protected ?string $messageType = null;

    protected ?string $textBody = null;

    protected bool $previewUrl = false;

    protected ?string $mediaUrlOrId = null;

    protected ?string $caption = null;

    protected ?string $filename = null;

    // Location
    protected ?float $latitude = null;

    protected ?float $longitude = null;

    protected ?string $locationName = null;

    protected ?string $locationAddress = null;

    // Template
    protected ?string $templateName = null;

    protected ?string $templateLanguage = 'pt_BR';

    protected ?string $headerType = null;

    protected ?string $headerValue = null;

    /** @var array<int, string> */
    protected array $bodyParameters = [];

    /** @var array<int, string> */
    protected array $buttonParameters = [];

    // Interactive Buttons
    protected ?string $interactiveBody = null;

    protected ?string $interactiveHeader = null;

    protected ?string $interactiveFooter = null;

    /** @var array<int, array{id: string, title: string}> */
    protected array $buttons = [];

    // Interactive List
    protected ?string $listButtonText = null;

    /** @var array<int, array{title: string, rows: array<int, array{id: string, title: string, description?: string}>}> */
    protected array $sections = [];

    // CTA URL
    protected ?string $ctaButtonText = null;

    protected ?string $ctaUrl = null;

    // Flow
    protected ?string $flowId = null;

    protected ?string $flowCta = null;

    protected ?string $flowTokenValue = null;

    protected ?string $flowScreen = null;

    /** @var array<string, mixed> */
    protected array $flowData = [];

    protected string $flowAction = 'navigate';

    protected ?string $flowMode = null;

    // Contacts
    /** @var array<int, array<string, mixed>> */
    protected array $contacts = [];

    // Reaction
    protected ?string $reactionMessageId = null;

    protected ?string $reactionEmoji = null;

    public function __construct(
        protected WhatsAppPhone $phone,
        protected WhatsAppClientInterface $client,
    ) {}

    public function to(string $phone): self
    {
        // Normalize phone number to E.164 format for consistent storage
        $this->to = PhoneNumberHelper::normalize($phone);

        return $this;
    }

    /**
     * Address the message to an existing conversation's contact and link it to that conversation.
     */
    public function conversation(WhatsAppConversation $conversation): self
    {
        $this->conversation = $conversation;
        $this->to = $conversation->contact_phone;

        return $this;
    }

    // Text
    public function text(string $body): self
    {
        $this->messageType = 'text';
        $this->textBody = $body;

        return $this;
    }

    public function previewUrl(bool $preview = true): self
    {
        $this->previewUrl = $preview;

        return $this;
    }

    // Media
    public function image(string $urlOrMediaId): self
    {
        $this->messageType = 'image';
        $this->mediaUrlOrId = $urlOrMediaId;

        return $this;
    }

    public function video(string $urlOrMediaId): self
    {
        $this->messageType = 'video';
        $this->mediaUrlOrId = $urlOrMediaId;

        return $this;
    }

    public function audio(string $urlOrMediaId): self
    {
        $this->messageType = 'audio';
        $this->mediaUrlOrId = $urlOrMediaId;

        return $this;
    }

    public function document(string $urlOrMediaId): self
    {
        $this->messageType = 'document';
        $this->mediaUrlOrId = $urlOrMediaId;

        return $this;
    }

    public function sticker(string $urlOrMediaId): self
    {
        $this->messageType = 'sticker';
        $this->mediaUrlOrId = $urlOrMediaId;

        return $this;
    }

    public function caption(string $caption): self
    {
        $this->caption = $caption;

        return $this;
    }

    public function filename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    // Location
    public function location(float $lat, float $lng, ?string $name = null, ?string $address = null): self
    {
        $this->messageType = 'location';
        $this->latitude = $lat;
        $this->longitude = $lng;
        $this->locationName = $name;
        $this->locationAddress = $address;

        return $this;
    }

    // Template
    public function template(string $name): self
    {
        $this->messageType = 'template';
        $this->templateName = $name;

        return $this;
    }

    public function language(string $code): self
    {
        $this->templateLanguage = $code;

        return $this;
    }

    public function headerImage(string $url): self
    {
        $this->headerType = 'image';
        $this->headerValue = $url;

        return $this;
    }

    public function headerVideo(string $url): self
    {
        $this->headerType = 'video';
        $this->headerValue = $url;

        return $this;
    }

    public function headerDocument(string $url): self
    {
        $this->headerType = 'document';
        $this->headerValue = $url;

        return $this;
    }

    public function headerText(string $text): self
    {
        $this->headerType = 'text';
        $this->headerValue = $text;

        return $this;
    }

    /**
     * @param  array<int, string>  $params
     */
    public function bodyParameters(array $params): self
    {
        $this->bodyParameters = $params;

        return $this;
    }

    /**
     * @param  array<int, string>  $params
     */
    public function buttonParameters(array $params): self
    {
        $this->buttonParameters = $params;

        return $this;
    }

    // Interactive Buttons
    public function buttons(string $body): self
    {
        $this->messageType = 'buttons';
        $this->interactiveBody = $body;

        return $this;
    }

    public function button(string $id, string $title): self
    {
        $this->buttons[] = ['id' => $id, 'title' => $title];

        return $this;
    }

    public function header(string $text): self
    {
        $this->interactiveHeader = $text;

        return $this;
    }

    public function footer(string $text): self
    {
        $this->interactiveFooter = $text;

        return $this;
    }

    // Interactive List
    public function list(string $body, string $buttonText): self
    {
        $this->messageType = 'list';
        $this->interactiveBody = $body;
        $this->listButtonText = $buttonText;

        return $this;
    }

    /**
     * @param  array<int, array{id: string, title: string, description?: string}>  $rows
     */
    public function section(string $title, array $rows): self
    {
        $this->sections[] = ['title' => $title, 'rows' => $rows];

        return $this;
    }

    // CTA URL
    public function ctaUrl(string $body): self
    {
        $this->messageType = 'cta_url';
        $this->interactiveBody = $body;

        return $this;
    }

    public function buttonText(string $text): self
    {
        $this->ctaButtonText = $text;

        return $this;
    }

    public function url(string $url): self
    {
        $this->ctaUrl = $url;

        return $this;
    }

    // Flow
    /**
     * Send a WhatsApp Flow CTA message.
     */
    public function flow(string $body, string $flowId, string $cta): self
    {
        $this->messageType = 'flow';
        $this->interactiveBody = $body;
        $this->flowId = $flowId;
        $this->flowCta = $cta;

        return $this;
    }

    /**
     * Set the flow token echoed back on the flow response. Generated when omitted.
     */
    public function flowToken(string $token): self
    {
        $this->flowTokenValue = $token;

        return $this;
    }

    /**
     * Set the initial screen and its data.
     *
     * @param  array<string, mixed>  $data
     */
    public function flowScreen(string $screen, array $data = []): self
    {
        $this->flowScreen = $screen;
        $this->flowData = $data;

        return $this;
    }

    /**
     * Use `data_exchange` instead of `navigate` (endpoint-backed flows).
     */
    public function flowDataExchange(): self
    {
        $this->flowAction = 'data_exchange';

        return $this;
    }

    /**
     * Send an unpublished flow (`draft` mode) for testing.
     */
    public function flowMode(string $mode): self
    {
        $this->flowMode = $mode;

        return $this;
    }

    // Contacts
    /**
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function contacts(array $contacts): self
    {
        $this->messageType = 'contacts';
        $this->contacts = $contacts;

        return $this;
    }

    // Reaction
    public function reaction(string $messageId, string $emoji): self
    {
        $this->messageType = 'reaction';
        $this->reactionMessageId = $messageId;
        $this->reactionEmoji = $emoji;

        return $this;
    }

    public function send(): WhatsAppMessage
    {
        $this->ensureRecipient();

        $result = $this->executeApiCall();

        $message = $this->createMessageRecord($result);

        event(new MessageSent($message));

        return $message;
    }

    public function queue(): WhatsAppMessage
    {
        $this->ensureRecipient();

        $message = $this->createPendingMessage();

        WhatsAppSendMessage::dispatch($message);

        return $message;
    }

    /**
     * @return array{messages: array<int, array{id: string}>}
     */
    protected function executeApiCall(): array
    {
        return match ($this->messageType) {
            'text' => $this->client->sendText(
                $this->to,
                $this->textBody ?? '',
                $this->previewUrl
            ),
            'image' => $this->client->sendImage(
                $this->to,
                $this->mediaUrlOrId ?? '',
                $this->caption
            ),
            'video' => $this->client->sendVideo(
                $this->to,
                $this->mediaUrlOrId ?? '',
                $this->caption
            ),
            'audio' => $this->client->sendAudio(
                $this->to,
                $this->mediaUrlOrId ?? ''
            ),
            'document' => $this->client->sendDocument(
                $this->to,
                $this->mediaUrlOrId ?? '',
                $this->filename,
                $this->caption
            ),
            'sticker' => $this->client->sendSticker(
                $this->to,
                $this->mediaUrlOrId ?? ''
            ),
            'location' => $this->client->sendLocation(
                $this->to,
                $this->latitude ?? 0,
                $this->longitude ?? 0,
                $this->locationName,
                $this->locationAddress
            ),
            'template' => $this->client->sendTemplate(
                $this->to,
                $this->templateName ?? '',
                $this->buildTemplateComponents(),
                $this->templateLanguage ?? 'pt_BR'
            ),
            'buttons' => $this->client->sendButtons(
                $this->to,
                $this->interactiveBody ?? '',
                $this->buttons,
                $this->interactiveHeader,
                $this->interactiveFooter
            ),
            'list' => $this->client->sendList(
                $this->to,
                $this->interactiveBody ?? '',
                $this->listButtonText ?? '',
                $this->sections,
                $this->interactiveHeader,
                $this->interactiveFooter
            ),
            'cta_url' => $this->client->sendCtaUrl(
                $this->to,
                $this->interactiveBody ?? '',
                $this->ctaButtonText ?? '',
                $this->ctaUrl ?? '',
                $this->interactiveHeader,
                $this->interactiveFooter
            ),
            'flow' => $this->client->sendFlow(
                $this->to,
                $this->interactiveBody ?? '',
                $this->flowId ?? '',
                $this->flowCta ?? '',
                $this->resolveFlowToken(),
                $this->flowScreen,
                $this->flowData,
                $this->flowAction,
                $this->flowMode,
                $this->interactiveHeader,
                $this->interactiveFooter
            ),
            'contacts' => $this->client->sendContacts(
                $this->to,
                $this->contacts
            ),
            'reaction' => $this->client->sendReaction(
                $this->to,
                $this->reactionMessageId ?? '',
                $this->reactionEmoji ?? ''
            ),
            default => throw new \InvalidArgumentException("Unknown message type: {$this->messageType}"),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildTemplateComponents(): array
    {
        $components = [];

        if ($this->headerType !== null && $this->headerValue !== null) {
            $headerComponent = ['type' => 'header', 'parameters' => []];

            if ($this->headerType === 'text') {
                $headerComponent['parameters'][] = ['type' => 'text', 'text' => $this->headerValue];
            } else {
                $headerComponent['parameters'][] = [
                    'type' => $this->headerType,
                    $this->headerType => ['link' => $this->headerValue],
                ];
            }

            $components[] = $headerComponent;
        }

        if (! empty($this->bodyParameters)) {
            $bodyComponent = ['type' => 'body', 'parameters' => []];
            foreach ($this->bodyParameters as $param) {
                $bodyComponent['parameters'][] = ['type' => 'text', 'text' => $param];
            }
            $components[] = $bodyComponent;
        }

        if (! empty($this->buttonParameters)) {
            foreach ($this->buttonParameters as $index => $param) {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => $index,
                    'parameters' => [['type' => 'text', 'text' => $param]],
                ];
            }
        }

        return $components;
    }

    /**
     * @param  array{messages: array<int, array{id: string}>}  $result
     */
    protected function createMessageRecord(array $result): WhatsAppMessage
    {
        $messageId = $result['messages'][0]['id'] ?? 'unknown_'.uniqid();

        return WhatsAppMessage::create([
            'whatsapp_phone_id' => $this->phone->id,
            'whatsapp_conversation_id' => $this->resolveConversation()->id,
            'message_id' => $messageId,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => $this->resolveMessageTypeForRecord(),
            'from' => $this->phone->phone_number,
            'to' => $this->to,
            'content' => $this->buildContentForRecord(),
            'text_body' => $this->textBody,
            'status' => WhatsAppMessage::STATUS_PROCESSED,
            'delivery_status' => WhatsAppMessage::DELIVERY_STATUS_SENT,
            'sent_at' => now(),
            'template_name' => $this->templateName,
            'template_parameters' => $this->buildTemplateParametersForRecord(),
        ]);
    }

    protected function createPendingMessage(): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'whatsapp_phone_id' => $this->phone->id,
            'whatsapp_conversation_id' => $this->resolveConversation()->id,
            'message_id' => 'pending_'.uniqid(),
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => $this->resolveMessageTypeForRecord(),
            'from' => $this->phone->phone_number,
            'to' => $this->to,
            'content' => $this->buildContentForRecord(),
            'text_body' => $this->textBody,
            'status' => WhatsAppMessage::STATUS_RECEIVED,
            'delivery_status' => WhatsAppMessage::DELIVERY_STATUS_QUEUED,
            'template_name' => $this->templateName,
            'template_parameters' => $this->buildTemplateParametersForRecord(),
        ]);
    }

    protected function ensureRecipient(): string
    {
        if ($this->to === null) {
            throw new \InvalidArgumentException('A recipient is required: call to() or conversation() before sending.');
        }

        return $this->to;
    }

    protected function resolveConversation(): WhatsAppConversation
    {
        return $this->conversation = OutboundConversationResolver::resolve(
            $this->phone,
            $this->ensureRecipient(),
            $this->conversation
        );
    }

    /**
     * Return the configured flow token, generating a stable one on first use.
     */
    protected function resolveFlowToken(): string
    {
        return $this->flowTokenValue ??= (string) Str::uuid();
    }

    protected function resolveMessageTypeForRecord(): string
    {
        return match ($this->messageType) {
            'buttons', 'list', 'cta_url', 'flow' => 'interactive',
            default => $this->messageType ?? 'text',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildContentForRecord(): array
    {
        return match ($this->messageType) {
            'text' => ['body' => $this->textBody, 'preview_url' => $this->previewUrl],
            'image', 'video', 'audio', 'document', 'sticker' => array_filter([
                'url' => $this->mediaUrlOrId,
                'caption' => $this->caption,
                'filename' => $this->filename,
            ]),
            'location' => array_filter([
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'name' => $this->locationName,
                'address' => $this->locationAddress,
            ]),
            'buttons' => [
                'type' => 'button',
                'body' => $this->interactiveBody,
                'header' => $this->interactiveHeader,
                'footer' => $this->interactiveFooter,
                'buttons' => $this->buttons,
            ],
            'list' => [
                'type' => 'list',
                'body' => $this->interactiveBody,
                'header' => $this->interactiveHeader,
                'footer' => $this->interactiveFooter,
                'button_text' => $this->listButtonText,
                'sections' => $this->sections,
            ],
            'cta_url' => [
                'type' => 'cta_url',
                'body' => $this->interactiveBody,
                'header' => $this->interactiveHeader,
                'footer' => $this->interactiveFooter,
                'button_text' => $this->ctaButtonText,
                'url' => $this->ctaUrl,
            ],
            'flow' => array_filter([
                'type' => 'flow',
                'body' => $this->interactiveBody,
                'header' => $this->interactiveHeader,
                'footer' => $this->interactiveFooter,
                'flow_id' => $this->flowId,
                'flow_token' => $this->resolveFlowToken(),
                'flow_cta' => $this->flowCta,
                'flow_action' => $this->flowAction,
                'mode' => $this->flowMode,
                'screen' => $this->flowScreen,
                'data' => $this->flowData ?: null,
            ], fn ($value) => $value !== null),
            'contacts' => ['contacts' => $this->contacts],
            'reaction' => ['message_id' => $this->reactionMessageId, 'emoji' => $this->reactionEmoji],
            'template' => [
                'name' => $this->templateName,
                'language' => $this->templateLanguage,
                'components' => $this->buildTemplateComponents(),
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildTemplateParametersForRecord(): ?array
    {
        if ($this->messageType !== 'template') {
            return null;
        }

        return [
            'header' => $this->headerValue,
            'body' => $this->bodyParameters,
            'buttons' => $this->buttonParameters,
        ];
    }
}
