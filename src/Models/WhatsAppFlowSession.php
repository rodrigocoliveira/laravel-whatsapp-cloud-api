<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One flow message sent: what its `flow_token` means.
 *
 * Meta's endpoint requests carry only the token, so this is how the endpoint knows
 * which phone, conversation and flow a request belongs to.
 *
 * @property int $id
 * @property string $flow_token
 * @property int|null $whatsapp_flow_id
 * @property int $whatsapp_message_id
 * @property int|null $response_message_id
 * @property string $status
 * @property array<string, mixed>|null $state
 * @property array<string, mixed>|null $result
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsAppFlow|null $flow
 * @property-read WhatsAppMessage $message
 * @property-read WhatsAppMessage|null $responseMessage
 */
class WhatsAppFlowSession extends Model
{
    protected $table = 'whatsapp_flow_sessions';

    public const STATUS_SENT = 'sent';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'flow_token',
        'whatsapp_flow_id',
        'whatsapp_message_id',
        'response_message_id',
        'status',
        'state',
        'result',
        'completed_at',
    ];

    protected $casts = [
        'state' => 'array',
        'result' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<WhatsAppFlow, $this>
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(WhatsAppFlow::class, 'whatsapp_flow_id');
    }

    /**
     * The outbound flow message this session was created for.
     *
     * @return BelongsTo<WhatsAppMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_id');
    }

    /**
     * The inbound `nfm_reply` that closed the flow.
     *
     * @return BelongsTo<WhatsAppMessage, $this>
     */
    public function responseMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'response_message_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Whether a completed session refuses further requests (HTTP 427).
     */
    public function isSingleUse(): bool
    {
        return $this->flow->single_use ?? (bool) config('whatsapp.flows.single_use', true);
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        $hours = $this->flow->session_ttl_hours ?? config('whatsapp.flows.session_ttl_hours');

        return $hours !== null && $this->created_at?->copy()->addHours((int) $hours)->isPast() === true;
    }

    /**
     * Keep data between screens.
     *
     * @param  array<string, mixed>  $data
     */
    public function remember(array $data): self
    {
        $this->update(['state' => array_merge($this->state ?? [], $data)]);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markAsCompleted(array $result = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'result' => $result ?: $this->result,
            'completed_at' => $this->completed_at ?? now(),
        ]);
    }
}
