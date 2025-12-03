<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * @property int $id
 * @property array $payload
 * @property bool $processed
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class WhatsAppWebhookLog extends Model
{
    use Prunable;

    protected $table = 'whatsapp_webhook_logs';

    protected $fillable = [
        'payload',
        'processed',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed' => 'boolean',
    ];

    /**
     * Get the prunable model query.
     *
     * Prunes logs older than configured retention days (default: 30 days).
     * Run `php artisan model:prune --model="Multek\LaravelWhatsAppCloud\Models\WhatsAppWebhookLog"` to prune.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function prunable()
    {
        $days = (int) config('whatsapp.webhook.log_retention_days', 30);

        return static::where('created_at', '<=', now()->subDays($days));
    }

    /**
     * Mark the log as processed.
     */
    public function markAsProcessed(): void
    {
        $this->update(['processed' => true]);
    }

    /**
     * Mark the log as failed with an error message.
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'processed' => false,
            'error' => $error,
        ]);
    }
}
