<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Events\MessageSent;
use Multek\LaravelWhatsAppCloud\Exceptions\MessageSendException;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSendMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppFlow;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppFlowSession;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Services\MediaService;
use SplFileInfo;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

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

    protected ?SplFileInfo $mediaFile = null;

    /** @var array<string, mixed> */
    protected array $mediaAttributes = [];

    // Location
    protected ?float $latitude = null;

    protected ?float $longitude = null;

    protected ?string $locationName = null;

    protected ?string $locationAddress = null;

    // Template
    protected ?string $templateName = null;

    protected ?string $templateLanguage = 'pt_BR';

    protected TemplateComponents $template;

    // Interactive Buttons
    protected ?string $interactiveBody = null;

    /** @var string|array{type: 'image', image: string}|null */
    protected string|array|null $interactiveHeader = null;

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

    protected ?WhatsAppFlow $flowModel = null;

    // Contacts
    /** @var array<int, array<string, mixed>> */
    protected array $contacts = [];

    // Reaction
    protected ?string $reactionMessageId = null;

    protected ?string $reactionEmoji = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    public function __construct(
        protected WhatsAppPhone $phone,
        protected WhatsAppClientInterface $client,
    ) {
        $this->template = new TemplateComponents;
    }

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

    /**
     * Stamp the outbound message record with extra metadata, merged before it is
     * persisted and before any events fire.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

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

    // Media: a string is a URL or a Meta media id; an UploadedFile or Illuminate\Http\File
    // is uploaded to Meta and kept on the media disk.
    public function image(SplFileInfo|string $source): self
    {
        return $this->media('image', $source);
    }

    public function video(SplFileInfo|string $source): self
    {
        return $this->media('video', $source);
    }

    public function audio(SplFileInfo|string $source): self
    {
        return $this->media('audio', $source);
    }

    public function document(SplFileInfo|string $source): self
    {
        return $this->media('document', $source);
    }

    public function sticker(SplFileInfo|string $source): self
    {
        return $this->media('sticker', $source);
    }

    protected function media(string $type, SplFileInfo|string $source): self
    {
        $this->messageType = $type;
        $this->mediaFile = $source instanceof SplFileInfo ? $source : null;
        $this->mediaUrlOrId = is_string($source) ? $source : null;
        $this->mediaAttributes = [];

        if ($type === 'document' && $this->mediaFile !== null) {
            $this->filename ??= $this->localFileName($this->mediaFile);
        }

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

    // Template header media: a file is uploaded to Meta, a digits-only string is a media id,
    // any other string is a link.
    public function headerImage(SplFileInfo|string $source): self
    {
        $this->template->headerMedia('image', $source);
        $this->interactiveHeader = is_string($source) ? ['type' => 'image', 'image' => $source] : null;

        return $this;
    }

    public function headerVideo(SplFileInfo|string $source): self
    {
        $this->template->headerMedia('video', $source);

        return $this;
    }

    public function headerDocument(SplFileInfo|string $source, ?string $filename = null): self
    {
        $this->template->headerMedia('document', $source, $filename);

        return $this;
    }

    public function headerText(string $text, ?string $name = null): self
    {
        $this->template->headerText($text, $name);

        return $this;
    }

    /**
     * A list sends positional parameters; string keys send named ones (parameter_name).
     *
     * @param  array<int|string, string|int|float>  $params
     */
    public function bodyParameters(array $params): self
    {
        $this->template->body($params);

        return $this;
    }

    /**
     * URL button suffixes keyed by button index: [1 => 'PED-1'] targets the second button.
     *
     * @param  array<int, string>  $params
     */
    public function buttonParameters(array $params): self
    {
        foreach ($params as $index => $suffix) {
            $this->template->urlButton((int) $index, (string) $suffix);
        }

        return $this;
    }

    public function urlButton(int $index, string $suffix): self
    {
        $this->template->urlButton($index, $suffix);

        return $this;
    }

    public function copyCodeButton(int $index, string $code): self
    {
        $this->template->copyCodeButton($index, $code);

        return $this;
    }

    public function quickReplyButton(int $index, string $payload): self
    {
        $this->template->quickReplyButton($index, $payload);

        return $this;
    }

    /**
     * Template components exactly as Meta documents them, for anything the helpers don't cover.
     *
     * @param  array<int, array<string, mixed>>  $metaComponents
     */
    public function components(array $metaComponents): self
    {
        $this->template->raw($metaComponents);

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
     * Send a synced flow by name (see `whatsapp:sync-flows`), looked up on the current phone.
     *
     * A flow with a handler is opened with `data_exchange`, so its first screen calls the endpoint.
     */
    public function flowNamed(string $name, string $body, string $cta): self
    {
        $this->flowModel = $this->phone->flows()
            ->where('name', $name)
            ->where('status', '!=', WhatsAppFlow::STATUS_DELETED)
            ->first()
            ?? throw new \InvalidArgumentException("No flow named '{$name}' on phone '{$this->phone->key}'. Run whatsapp:sync-flows first.");

        $this->flow($body, $this->flowModel->flow_id, $cta);

        if ($this->flowModel->handler !== null) {
            $this->flowDataExchange();
        }

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
        $this->ensureHeaderFileIsTemplate();
        $this->uploadMediaFile();

        $result = $this->executeApiCall();

        $message = $this->createMessageRecord($result);
        $this->recordFlowSession($message);

        event(new MessageSent($message));

        return $message;
    }

    public function queue(): WhatsAppMessage
    {
        $this->ensureRecipient();
        $this->ensureHeaderFileIsTemplate();
        $this->storeMediaFile();

        $message = $this->createPendingMessage();
        $this->recordFlowSession($message);

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
        return $this->template->toComponents();
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
            'metadata' => $this->metadata ?: null,
        ] + $this->mediaAttributes + $this->templateMediaAttributes());
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
            'metadata' => $this->metadata ?: null,
        ] + $this->mediaAttributes + $this->templateMediaAttributes());
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
     * Remember what this flow token means, so the endpoint can tell who it is serving.
     *
     * A reused token starts over on the newest message.
     */
    protected function recordFlowSession(WhatsAppMessage $message): void
    {
        if ($this->messageType !== 'flow') {
            return;
        }

        $flow = $this->flowModel ?? $this->phone->flows()->where('flow_id', $this->flowId)->first();

        // Recreated, not updated, so a reused token's TTL counts from this send.
        WhatsAppFlowSession::where('flow_token', $this->resolveFlowToken())->delete();

        WhatsAppFlowSession::create([
            'flow_token' => $this->resolveFlowToken(),
            'whatsapp_flow_id' => $flow?->id,
            'whatsapp_message_id' => $message->id,
            'status' => WhatsAppFlowSession::STATUS_SENT,
        ]);
    }

    /**
     * Return the configured flow token, generating a stable one on first use.
     */
    protected function resolveFlowToken(): string
    {
        return $this->flowTokenValue ??= (string) Str::uuid();
    }

    /**
     * Upload the local file to Meta and keep a copy on the media disk, so the record
     * is complete (media id, local path, size) by the time MessageSent fires.
     */
    protected function uploadMediaFile(): void
    {
        $file = $this->pendingFile();

        if ($file === null) {
            return;
        }

        $contents = (string) file_get_contents($file->getPathname());
        $mimeType = $this->fileMimeType($file);

        $result = $this->client->uploadMediaContents($contents, $mimeType, $this->localFileName($file));
        $mediaId = $result['id'] ?? throw MessageSendException::mediaUploadFailed('no media id returned');

        $this->storeMediaFile($contents, $mimeType);

        if ($this->messageType === 'template') {
            $this->template->resolveHeaderMedia($mediaId);
        } else {
            $this->mediaUrlOrId = $mediaId;
        }

        $this->mediaAttributes['media_id'] = $mediaId;
    }

    /**
     * Keep a copy on the media disk; a queued message is uploaded from it by the job.
     */
    protected function storeMediaFile(?string $contents = null, ?string $mimeType = null): void
    {
        $file = $this->pendingFile();

        if ($file === null || isset($this->mediaAttributes['local_media_path'])) {
            return;
        }

        $contents ??= (string) file_get_contents($file->getPathname());
        $mimeType ??= $this->fileMimeType($file);

        ['disk' => $disk, 'path' => $path] = app(MediaService::class)->store($contents, $mimeType);

        $this->mediaAttributes += [
            'media_mime_type' => $mimeType,
            'media_size' => (int) $file->getSize(),
            'media_status' => WhatsAppMessage::MEDIA_STATUS_DOWNLOADED,
            'local_media_disk' => $disk,
            'local_media_path' => $path,
        ];
    }

    protected function fileMimeType(SplFileInfo $file): string
    {
        $mimeType = $file instanceof SymfonyFile ? $file->getMimeType() : mime_content_type($file->getPathname());

        return $mimeType ?: 'application/octet-stream';
    }

    protected function localFileName(SplFileInfo $file): string
    {
        return $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();
    }

    /**
     * The local file this message sends: the media file, or a template's header file.
     */
    protected function pendingFile(): ?SplFileInfo
    {
        return $this->messageType === 'template' ? $this->template->headerFile() : $this->mediaFile;
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
                'url' => $this->mediaFile === null ? $this->mediaUrlOrId : null,
                'id' => $this->mediaFile === null ? null : $this->mediaUrlOrId,
                'mime_type' => $this->mediaAttributes['media_mime_type'] ?? null,
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
        return $this->messageType === 'template' ? $this->template->toRecord() : null;
    }

    /**
     * A template header sent by media id records it like any outbound media.
     *
     * @return array<string, string>
     */
    protected function templateMediaAttributes(): array
    {
        $mediaId = $this->messageType === 'template' ? $this->template->headerMediaId() : null;

        return $mediaId === null ? [] : ['media_id' => $mediaId];
    }

    protected function ensureHeaderFileIsTemplate(): void
    {
        if ($this->messageType !== 'template' && $this->template->headerFile() !== null) {
            throw new \InvalidArgumentException('A header file can only be sent with a template; pass a URL or media id for an interactive header.');
        }
    }
}
