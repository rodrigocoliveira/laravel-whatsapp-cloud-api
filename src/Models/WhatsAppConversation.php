<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Multek\LaravelWhatsAppCloud\Support\PhoneNumberHelper;

/**
 * @property int $id
 * @property int $whatsapp_phone_id
 * @property string $contact_phone
 * @property string|null $contact_name
 * @property Carbon $last_message_at
 * @property Carbon|null $last_inbound_message_at
 * @property string $status
 * @property int $unread_count
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsAppPhone $phone
 * @property-read Collection<int, WhatsAppMessage> $messages
 * @property-read Collection<int, WhatsAppMessageBatch> $batches
 */
class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'whatsapp_phone_id',
        'contact_phone',
        'contact_name',
        'last_message_at',
        'last_inbound_message_at',
        'status',
        'unread_count',
        'metadata',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_inbound_message_at' => 'datetime',
        'unread_count' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * The WhatsApp customer service window: 24 hours from the contact's last
     * inbound message. Outbound replies never extend it.
     */
    public const SERVICE_WINDOW_HOURS = 24;

    /**
     * @return BelongsTo<WhatsAppPhone, $this>
     */
    public function phone(): BelongsTo
    {
        return $this->belongsTo(WhatsAppPhone::class, 'whatsapp_phone_id');
    }

    /**
     * @return HasMany<WhatsAppMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_conversation_id');
    }

    /**
     * @return HasMany<WhatsAppMessageBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(WhatsAppMessageBatch::class, 'whatsapp_conversation_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function incrementUnread(): void
    {
        $this->increment('unread_count');
    }

    public function markAsRead(): void
    {
        $this->update(['unread_count' => 0]);
    }

    public function close(): void
    {
        $this->update(['status' => self::STATUS_CLOSED]);
    }

    public function archive(): void
    {
        $this->update(['status' => self::STATUS_ARCHIVED]);
    }

    public function reopen(): void
    {
        $this->update(['status' => self::STATUS_ACTIVE]);
    }

    /**
     * Whether the WhatsApp 24-hour customer service window is still open,
     * i.e. free-form messages can be sent without a template.
     */
    public function isWithin24HourWindow(): bool
    {
        if ($this->last_inbound_message_at === null) {
            return false;
        }

        return $this->last_inbound_message_at->addHours(self::SERVICE_WINDOW_HOURS)->isFuture();
    }

    /**
     * When the 24-hour customer service window closes, or null if the
     * contact has never messaged in.
     */
    public function serviceWindowExpiresAt(): ?Carbon
    {
        if ($this->last_inbound_message_at === null) {
            return null;
        }

        return $this->last_inbound_message_at->clone()->addHours(self::SERVICE_WINDOW_HOURS);
    }

    /**
     * Normalize contact_phone to E.164 format when setting.
     */
    public function setContactPhoneAttribute(string $value): void
    {
        $this->attributes['contact_phone'] = PhoneNumberHelper::normalize($value);
    }
}
