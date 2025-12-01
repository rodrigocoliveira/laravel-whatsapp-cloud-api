<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $whatsapp_phone_id
 * @property string $template_id
 * @property string $name
 * @property string $language
 * @property string $category
 * @property string $status
 * @property array $components
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read WhatsAppPhone $phone
 */
class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    public const CATEGORY_AUTHENTICATION = 'AUTHENTICATION';

    public const CATEGORY_MARKETING = 'MARKETING';

    public const CATEGORY_UTILITY = 'UTILITY';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_DISABLED = 'DISABLED';

    protected $fillable = [
        'whatsapp_phone_id',
        'template_id',
        'name',
        'language',
        'category',
        'status',
        'components',
        'rejection_reason',
        'last_synced_at',
    ];

    protected $casts = [
        'components' => 'array',
        'last_synced_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<WhatsAppPhone, $this>
     */
    public function phone(): BelongsTo
    {
        return $this->belongsTo(WhatsAppPhone::class, 'whatsapp_phone_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    public function getHeaderComponent(): ?array
    {
        return collect($this->components)->firstWhere('type', 'HEADER');
    }

    public function getBodyComponent(): ?array
    {
        return collect($this->components)->firstWhere('type', 'BODY');
    }

    public function getFooterComponent(): ?array
    {
        return collect($this->components)->firstWhere('type', 'FOOTER');
    }

    public function getButtonsComponent(): ?array
    {
        return collect($this->components)->firstWhere('type', 'BUTTONS');
    }
}
