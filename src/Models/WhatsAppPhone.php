<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $key
 * @property string $phone_id
 * @property string $phone_number
 * @property string|null $display_name
 * @property string $business_account_id
 * @property string|null $access_token
 * @property string|null $handler
 * @property array|null $handler_config
 * @property string $processing_mode
 * @property int $batch_window_seconds
 * @property int $batch_max_messages
 * @property bool $auto_download_media
 * @property bool $transcription_enabled
 * @property string|null $transcription_service
 * @property string $transcription_language
 * @property array|null $allowed_message_types
 * @property string $on_disallowed_type
 * @property string|null $disallowed_type_reply
 * @property bool $is_active
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhatsAppConversation> $conversations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhatsAppMessage> $messages
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhatsAppMessageBatch> $batches
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhatsAppTemplate> $templates
 */
class WhatsAppPhone extends Model
{
    use SoftDeletes;

    protected $table = 'whatsapp_phones';

    protected $fillable = [
        'key',
        'phone_id',
        'phone_number',
        'display_name',
        'business_account_id',
        'access_token',
        'handler',
        'handler_config',
        'processing_mode',
        'batch_window_seconds',
        'batch_max_messages',
        'auto_download_media',
        'transcription_enabled',
        'transcription_service',
        'transcription_language',
        'allowed_message_types',
        'on_disallowed_type',
        'disallowed_type_reply',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'handler_config' => 'array',
        'allowed_message_types' => 'array',
        'auto_download_media' => 'boolean',
        'transcription_enabled' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'batch_window_seconds' => 'integer',
        'batch_max_messages' => 'integer',
    ];

    protected $attributes = [
        'processing_mode' => 'batch',
        'batch_window_seconds' => 3,
        'batch_max_messages' => 10,
        'auto_download_media' => true,
        'transcription_enabled' => false,
        'transcription_language' => 'pt-BR',
        'on_disallowed_type' => 'ignore',
        'is_active' => true,
    ];

    /**
     * @return HasMany<WhatsAppConversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class);
    }

    /**
     * @return HasMany<WhatsAppMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class);
    }

    /**
     * @return HasMany<WhatsAppMessageBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(WhatsAppMessageBatch::class);
    }

    /**
     * @return HasMany<WhatsAppTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class);
    }

    public function getAccessTokenAttribute(?string $value): string
    {
        return $value ?? config('whatsapp.access_token', '');
    }

    public function isMessageTypeAllowed(string $type): bool
    {
        $allowedTypes = $this->allowed_message_types;

        if ($allowedTypes === null || in_array('*', $allowedTypes, true)) {
            return true;
        }

        return in_array($type, $allowedTypes, true);
    }

    public function isBatchMode(): bool
    {
        return $this->processing_mode === 'batch';
    }

    public function isImmediateMode(): bool
    {
        return $this->processing_mode === 'immediate';
    }
}
