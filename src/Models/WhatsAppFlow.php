<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A flow that exists on Meta, mirrored by `whatsapp:sync-flows`.
 *
 * `handler`, `single_use` and `session_ttl_hours` belong to the app; the sync never
 * overwrites them.
 *
 * @property int $id
 * @property int $whatsapp_phone_id
 * @property string $flow_id
 * @property string $name
 * @property string $status
 * @property array<int, string>|null $categories
 * @property array<int, mixed>|null $validation_errors
 * @property string|null $handler
 * @property bool|null $single_use
 * @property int|null $session_ttl_hours
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsAppPhone $phone
 */
class WhatsAppFlow extends Model
{
    protected $table = 'whatsapp_flows';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_DEPRECATED = 'DEPRECATED';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const STATUS_THROTTLED = 'THROTTLED';

    /** No longer returned by Meta: the flow draft was deleted. */
    public const STATUS_DELETED = 'DELETED';

    protected $fillable = [
        'whatsapp_phone_id',
        'flow_id',
        'name',
        'status',
        'categories',
        'validation_errors',
        'handler',
        'single_use',
        'session_ttl_hours',
        'last_synced_at',
    ];

    protected $casts = [
        'categories' => 'array',
        'validation_errors' => 'array',
        'single_use' => 'boolean',
        'session_ttl_hours' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<WhatsAppPhone, $this>
     */
    public function phone(): BelongsTo
    {
        return $this->belongsTo(WhatsAppPhone::class, 'whatsapp_phone_id');
    }

    /**
     * @return HasMany<WhatsAppFlowSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(WhatsAppFlowSession::class, 'whatsapp_flow_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
