<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $whatsapp_phone_id
 * @property int $whatsapp_conversation_id
 * @property string $status
 * @property Carbon $first_message_at
 * @property Carbon $process_after
 * @property Carbon|null $processed_at
 * @property string|null $error_message
 * @property array|null $handler_result
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsAppPhone $phone
 * @property-read WhatsAppConversation $conversation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhatsAppMessage> $messages
 */
class WhatsAppMessageBatch extends Model
{
    protected $table = 'whatsapp_message_batches';

    public const STATUS_COLLECTING = 'collecting';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'whatsapp_phone_id',
        'whatsapp_conversation_id',
        'status',
        'first_message_at',
        'process_after',
        'processed_at',
        'error_message',
        'handler_result',
    ];

    protected $casts = [
        'first_message_at' => 'datetime',
        'process_after' => 'datetime',
        'processed_at' => 'datetime',
        'handler_result' => 'array',
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
     * @return HasMany<WhatsAppMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_message_batch_id');
    }

    public function isCollecting(): bool
    {
        return $this->status === self::STATUS_COLLECTING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the batch should be processed.
     *
     * Conditions:
     * 1. Process window has elapsed
     * 2. All messages are in 'ready' status
     * 3. OR max messages reached
     */
    public function shouldProcess(): bool
    {
        if (! $this->isCollecting()) {
            return false;
        }

        $phone = $this->phone;
        $messageCount = $this->messages()->count();

        // Check max messages
        if ($messageCount >= $phone->batch_max_messages) {
            return $this->allMessagesReady();
        }

        // Check window elapsed
        if (Carbon::now()->gte($this->process_after)) {
            return $this->allMessagesReady();
        }

        return false;
    }

    public function allMessagesReady(): bool
    {
        return $this->messages()
            ->whereNotIn('status', ['ready', 'processed'])
            ->doesntExist();
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(?array $handlerResult = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'processed_at' => Carbon::now(),
            'handler_result' => $handlerResult,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    public function extendWindow(int $seconds): void
    {
        $this->update([
            'process_after' => Carbon::now()->addSeconds($seconds),
        ]);
    }
}
