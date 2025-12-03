<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\AudioContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\ContactsContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\DocumentContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\ImageContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\InteractiveReplyContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\LocationContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\MessageContentInterface;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\OrderContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\ReactionContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\StickerContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\SystemContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\TextContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\UnknownContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\VideoContent;
use Multek\LaravelWhatsAppCloud\Events\MessageReady;
use Multek\LaravelWhatsAppCloud\Support\PhoneNumberHelper;

/**
 * @property int $id
 * @property int $whatsapp_phone_id
 * @property int|null $whatsapp_conversation_id
 * @property int|null $whatsapp_message_batch_id
 * @property string $message_id
 * @property string $direction
 * @property string $type
 * @property string $from
 * @property string $to
 * @property array|null $content
 * @property string|null $text_body
 * @property string $status
 * @property string|null $filtered_reason
 * @property string|null $media_id
 * @property string|null $media_status
 * @property string|null $local_media_path
 * @property string|null $local_media_disk
 * @property string|null $media_mime_type
 * @property int|null $media_size
 * @property string|null $transcription_status
 * @property string|null $transcription
 * @property string|null $transcription_language
 * @property float|null $transcription_duration
 * @property string|null $delivery_status
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property string|null $template_name
 * @property array|null $template_parameters
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read WhatsAppPhone $phone
 * @property-read WhatsAppConversation|null $conversation
 * @property-read WhatsAppMessageBatch|null $batch
 */
class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    // Direction constants
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    // Message types
    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_STICKER = 'sticker';

    public const TYPE_LOCATION = 'location';

    public const TYPE_CONTACTS = 'contacts';

    public const TYPE_INTERACTIVE = 'interactive';

    public const TYPE_BUTTON = 'button';

    public const TYPE_REACTION = 'reaction';

    public const TYPE_ORDER = 'order';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_UNSUPPORTED = 'unsupported';

    // Processing status
    public const STATUS_RECEIVED = 'received';

    public const STATUS_FILTERED = 'filtered';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    // Media status
    public const MEDIA_STATUS_PENDING = 'pending';

    public const MEDIA_STATUS_DOWNLOADING = 'downloading';

    public const MEDIA_STATUS_DOWNLOADED = 'downloaded';

    public const MEDIA_STATUS_FAILED = 'failed';

    // Transcription status
    public const TRANSCRIPTION_STATUS_PENDING = 'pending';

    public const TRANSCRIPTION_STATUS_TRANSCRIBING = 'transcribing';

    public const TRANSCRIPTION_STATUS_TRANSCRIBED = 'transcribed';

    public const TRANSCRIPTION_STATUS_FAILED = 'failed';

    // Delivery status
    public const DELIVERY_STATUS_QUEUED = 'queued';

    public const DELIVERY_STATUS_SENT = 'sent';

    public const DELIVERY_STATUS_DELIVERED = 'delivered';

    public const DELIVERY_STATUS_READ = 'read';

    public const DELIVERY_STATUS_FAILED = 'failed';

    // Media types
    public const MEDIA_TYPES = [
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
        self::TYPE_AUDIO,
        self::TYPE_DOCUMENT,
        self::TYPE_STICKER,
    ];

    protected $fillable = [
        'whatsapp_phone_id',
        'whatsapp_conversation_id',
        'whatsapp_message_batch_id',
        'message_id',
        'direction',
        'type',
        'from',
        'to',
        'content',
        'text_body',
        'status',
        'filtered_reason',
        'media_id',
        'media_status',
        'local_media_path',
        'local_media_disk',
        'media_mime_type',
        'media_size',
        'transcription_status',
        'transcription',
        'transcription_language',
        'transcription_duration',
        'delivery_status',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
        'template_name',
        'template_parameters',
        'metadata',
    ];

    protected $casts = [
        'content' => 'array',
        'template_parameters' => 'array',
        'metadata' => 'array',
        'media_size' => 'integer',
        'transcription_duration' => 'float',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<WhatsAppPhone, $this>
     */
    public function phone(): BelongsTo
    {
        return $this->belongsTo(WhatsAppPhone::class, 'whatsapp_phone_id');
    }

    /**
     * @return BelongsTo<WhatsAppConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whatsapp_conversation_id');
    }

    /**
     * @return BelongsTo<WhatsAppMessageBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessageBatch::class, 'whatsapp_message_batch_id', 'id');
    }

    /**
     * Get typed content DTO.
     */
    public function getTypedContent(): MessageContentInterface
    {
        $content = $this->content ?? [];

        return match ($this->type) {
            self::TYPE_TEXT => new TextContent($content['body'] ?? $this->text_body ?? ''),
            self::TYPE_IMAGE => new ImageContent(
                mediaId: $content['id'] ?? $this->media_id ?? '',
                caption: $content['caption'] ?? null,
                mimeType: $content['mime_type'] ?? $this->media_mime_type,
                sha256: $content['sha256'] ?? null,
            ),
            self::TYPE_VIDEO => new VideoContent(
                mediaId: $content['id'] ?? $this->media_id ?? '',
                caption: $content['caption'] ?? null,
                mimeType: $content['mime_type'] ?? $this->media_mime_type,
                sha256: $content['sha256'] ?? null,
            ),
            self::TYPE_AUDIO => new AudioContent(
                mediaId: $content['id'] ?? $this->media_id ?? '',
                mimeType: $content['mime_type'] ?? $this->media_mime_type,
                voice: $content['voice'] ?? false,
            ),
            self::TYPE_DOCUMENT => new DocumentContent(
                mediaId: $content['id'] ?? $this->media_id ?? '',
                filename: $content['filename'] ?? null,
                caption: $content['caption'] ?? null,
                mimeType: $content['mime_type'] ?? $this->media_mime_type,
                sha256: $content['sha256'] ?? null,
            ),
            self::TYPE_STICKER => new StickerContent(
                mediaId: $content['id'] ?? $this->media_id ?? '',
                mimeType: $content['mime_type'] ?? $this->media_mime_type,
                animated: $content['animated'] ?? false,
            ),
            self::TYPE_LOCATION => new LocationContent(
                latitude: (float) ($content['latitude'] ?? 0),
                longitude: (float) ($content['longitude'] ?? 0),
                name: $content['name'] ?? null,
                address: $content['address'] ?? null,
            ),
            self::TYPE_CONTACTS => new ContactsContent(contacts: $content['contacts'] ?? []),
            self::TYPE_INTERACTIVE, self::TYPE_BUTTON => new InteractiveReplyContent(
                replyType: $content['type'] ?? 'button_reply',
                id: $content['button_reply']['id'] ?? $content['list_reply']['id'] ?? '',
                title: $content['button_reply']['title'] ?? $content['list_reply']['title'] ?? '',
                description: $content['list_reply']['description'] ?? null,
            ),
            self::TYPE_REACTION => new ReactionContent(
                messageId: $content['message_id'] ?? '',
                emoji: $content['emoji'] ?? '',
            ),
            self::TYPE_ORDER => new OrderContent(
                catalogId: $content['catalog_id'] ?? '',
                productItems: $content['product_items'] ?? [],
                text: $content['text'] ?? null,
            ),
            self::TYPE_SYSTEM => new SystemContent(
                body: $content['body'] ?? '',
                identity: $content['identity'] ?? null,
                newWaId: $content['new_wa_id'] ?? null,
                type: $content['type'] ?? null,
            ),
            default => new UnknownContent(type: $this->type, data: $content),
        };
    }

    // Type checks
    public function isText(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }

    public function isMedia(): bool
    {
        return in_array($this->type, self::MEDIA_TYPES, true);
    }

    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    public function isAudio(): bool
    {
        return $this->type === self::TYPE_AUDIO;
    }

    public function isVoiceNote(): bool
    {
        if (! $this->isAudio()) {
            return false;
        }

        $content = $this->content ?? [];

        return $content['voice'] ?? false;
    }

    public function isDocument(): bool
    {
        return $this->type === self::TYPE_DOCUMENT;
    }

    public function isSticker(): bool
    {
        return $this->type === self::TYPE_STICKER;
    }

    public function isLocation(): bool
    {
        return $this->type === self::TYPE_LOCATION;
    }

    public function isContacts(): bool
    {
        return $this->type === self::TYPE_CONTACTS;
    }

    public function isInteractiveReply(): bool
    {
        return in_array($this->type, [self::TYPE_INTERACTIVE, self::TYPE_BUTTON], true);
    }

    public function isReaction(): bool
    {
        return $this->type === self::TYPE_REACTION;
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::DIRECTION_OUTBOUND;
    }

    // Media helpers
    public function hasMedia(): bool
    {
        return $this->isMedia() && $this->media_id !== null;
    }

    public function getMediaPath(): ?string
    {
        return $this->local_media_path;
    }

    public function getMediaUrl(): ?string
    {
        if ($this->local_media_path === null || $this->local_media_disk === null) {
            return null;
        }

        return Storage::disk($this->local_media_disk)->url($this->local_media_path);
    }

    public function getMediaFullPath(): ?string
    {
        if ($this->local_media_path === null || $this->local_media_disk === null) {
            return null;
        }

        return Storage::disk($this->local_media_disk)->path($this->local_media_path);
    }

    // Transcription
    public function hasTranscription(): bool
    {
        return $this->transcription !== null && $this->transcription !== '';
    }

    public function getTranscription(): ?string
    {
        return $this->transcription;
    }

    public function getTranscriptionLanguage(): ?string
    {
        return $this->transcription_language;
    }

    public function getTranscriptionDuration(): ?float
    {
        return $this->transcription_duration;
    }

    // Status helpers
    public function markAsReady(): void
    {
        $this->update(['status' => self::STATUS_READY]);

        event(new MessageReady($this));
    }

    public function markAsProcessed(): void
    {
        $this->update(['status' => self::STATUS_PROCESSED]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    public function markAsFiltered(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_FILTERED,
            'filtered_reason' => $reason,
        ]);
    }

    /**
     * Normalize 'from' phone number to E.164 format when setting.
     */
    public function setFromAttribute(string $value): void
    {
        $this->attributes['from'] = PhoneNumberHelper::normalize($value);
    }

    /**
     * Normalize 'to' phone number to E.164 format when setting.
     */
    public function setToAttribute(string $value): void
    {
        $this->attributes['to'] = PhoneNumberHelper::normalize($value);
    }
}
