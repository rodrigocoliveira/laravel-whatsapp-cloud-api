# Laravel WhatsApp Cloud API - Complete Implementation Specification

## Package Overview

**Name:** `multek/laravel-whatsapp-cloud`
**Namespace:** `Multek\LaravelWhatsAppCloud`
**Requirements:** Laravel 11+ / PHP 8.2+

### Core Features

- **Multi-phone number support** with per-phone handlers (support phone triggers SupportHandler, sales phone triggers SalesHandler)
- **Batch mode for AI agent workflows** - gather messages together before processing (user sends text + image + audio in quick succession, process all together)
- **Async job pipeline** with media download and audio transcription before handler is called
- **All WhatsApp inbound message types** with typed DTOs
- **Fluent API** for sending all outbound message types
- **Per-phone configuration** of allowed message types
- **Database-only phone management** (not config file)
- **Delayed jobs** for batch processing (not frequent scheduler)

---

## Architecture: Async Processing Pipeline

```
Webhook arrives (respond <5s)
    |
    +-> Save message to DB (status: 'received')
    +-> Check message type filtering
        |
        +-> If disallowed: Mark 'filtered', fire MessageFiltered event, return
        +-> If allowed: Dispatch WhatsAppProcessIncomingMessage job
    +-> Return 200 immediately

WhatsAppProcessIncomingMessage
    |
    +-> Find/create batch for conversation (with lockForUpdate)
    +-> Update batch window (process_after = now + batch_window_seconds)
    +-> Dispatch delayed WhatsAppCheckBatchReady job
    +-> If has media + auto_download_media:
        +-> Dispatch WhatsAppDownloadMedia
    +-> Else: Mark message 'ready'

WhatsAppDownloadMedia
    |
    +-> Download from WhatsApp API (URLs expire in ~5 min)
    +-> Save to storage (S3/local)
    +-> If audio + transcription_enabled:
        +-> Dispatch WhatsAppTranscribeAudio
    +-> Else: Mark message 'ready'

WhatsAppTranscribeAudio
    |
    +-> Transcribe audio file via configured service
    +-> Save transcription to message
    +-> Mark message 'ready'

When message marked 'ready':
    |
    +-> Check if batch.shouldProcess()
    +-> If ready: Dispatch WhatsAppProcessBatch

WhatsAppCheckBatchReady (delayed job - safety mechanism)
    |
    +-> Check if batch status is still 'collecting'
    +-> If batch.shouldProcess(): Dispatch WhatsAppProcessBatch

WhatsAppProcessBatch
    |
    +-> Mark batch 'processing'
    +-> Load all messages with downloaded media + transcriptions
    +-> Build IncomingMessageContext DTO
    +-> Instantiate phone's configured handler class
    +-> Call handler->handle($context)
    +-> Mark batch 'completed'

Safety Scheduler (every 5 minutes):
    |
    +-> Find batches stuck in 'collecting' past their process_after time
    +-> Dispatch WhatsAppProcessBatch for orphaned batches
```

---

## Database Schema

### 1. `whatsapp_phones` table

```php
Schema::create('whatsapp_phones', function (Blueprint $table) {
    $table->id();
    $table->string('key')->unique();              // 'support', 'sales', 'notifications'
    $table->string('phone_id');                   // WhatsApp Phone Number ID from Meta
    $table->string('phone_number');               // +5511999999999
    $table->string('display_name')->nullable();
    $table->string('business_account_id');
    $table->string('access_token')->nullable();   // Per-phone token (overrides global)

    // Handler configuration
    $table->string('handler')->nullable();        // App\WhatsApp\Handlers\SupportHandler::class
    $table->json('handler_config')->nullable();   // Handler-specific config JSON

    // Batch processing
    $table->enum('processing_mode', ['immediate', 'batch'])->default('batch');
    $table->integer('batch_window_seconds')->default(3);
    $table->integer('batch_max_messages')->default(10);

    // Media handling
    $table->boolean('auto_download_media')->default(true);
    $table->boolean('transcription_enabled')->default(false);
    $table->string('transcription_service')->nullable();  // 'openai', 'azure', 'whisper'
    $table->string('transcription_language')->default('pt-BR');

    // Message type filtering
    $table->json('allowed_message_types')->nullable();    // ['text','image','audio'] or ['*'] for all
    $table->enum('on_disallowed_type', ['ignore', 'log', 'auto_reply'])->default('ignore');
    $table->string('disallowed_type_reply')->nullable();  // "Please send text only"

    $table->boolean('is_active')->default(true);
    $table->json('metadata')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

### 2. `whatsapp_conversations` table

```php
Schema::create('whatsapp_conversations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('whatsapp_phone_id')->constrained()->cascadeOnDelete();
    $table->string('contact_phone');              // Customer phone number
    $table->string('contact_name')->nullable();   // From WhatsApp profile
    $table->timestamp('last_message_at');
    $table->enum('status', ['active', 'closed', 'archived'])->default('active');
    $table->integer('unread_count')->default(0);
    $table->json('metadata')->nullable();         // Custom fields for your app
    $table->timestamps();

    $table->unique(['whatsapp_phone_id', 'contact_phone']);
});
```

### 3. `whatsapp_message_batches` table

```php
Schema::create('whatsapp_message_batches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('whatsapp_phone_id')->constrained()->cascadeOnDelete();
    $table->foreignId('whatsapp_conversation_id')->constrained()->cascadeOnDelete();
    $table->enum('status', ['collecting', 'processing', 'completed', 'failed'])->default('collecting');
    $table->timestamp('first_message_at');
    $table->timestamp('process_after');           // When batch window expires
    $table->timestamp('processed_at')->nullable();
    $table->text('error_message')->nullable();
    $table->json('handler_result')->nullable();   // Result returned by handler
    $table->timestamps();

    $table->index(['status', 'process_after']);
});
```

### 4. `whatsapp_messages` table

```php
Schema::create('whatsapp_messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('whatsapp_phone_id')->constrained()->cascadeOnDelete();
    $table->foreignId('whatsapp_conversation_id')->nullable()->constrained();
    $table->foreignId('whatsapp_message_batch_id')->nullable()->constrained();
    $table->string('message_id')->unique();       // wamid.xxx from WhatsApp
    $table->enum('direction', ['inbound', 'outbound']);
    $table->string('type');                       // text, image, video, audio, document, etc.
    $table->string('from');
    $table->string('to');
    $table->json('content')->nullable();          // Raw webhook payload
    $table->text('text_body')->nullable();        // Extracted text for search

    // Processing status
    $table->enum('status', [
        'received',      // Just arrived
        'filtered',      // Message type not allowed
        'processing',    // Media downloading or transcribing
        'ready',         // Ready for batch processing
        'processed',     // Handler was called
        'failed'         // Processing failed
    ])->default('received');
    $table->string('filtered_reason')->nullable();

    // Media processing
    $table->string('media_id')->nullable();       // WhatsApp media ID
    $table->enum('media_status', ['pending', 'downloading', 'downloaded', 'failed'])->nullable();
    $table->string('local_media_path')->nullable();
    $table->string('local_media_disk')->nullable();
    $table->string('media_mime_type')->nullable();
    $table->integer('media_size')->nullable();

    // Audio transcription
    $table->enum('transcription_status', ['pending', 'transcribing', 'transcribed', 'failed'])->nullable();
    $table->text('transcription')->nullable();

    // Outbound delivery status
    $table->enum('delivery_status', ['queued', 'sent', 'delivered', 'read', 'failed'])->nullable();
    $table->text('error_message')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('delivered_at')->nullable();
    $table->timestamp('read_at')->nullable();

    // Template info (for outbound)
    $table->string('template_name')->nullable();
    $table->json('template_parameters')->nullable();

    $table->json('metadata')->nullable();
    $table->timestamps();

    $table->index(['from', 'created_at']);
    $table->index(['status', 'created_at']);
    $table->index('whatsapp_message_batch_id');
});
```

### 5. `whatsapp_templates` table

```php
Schema::create('whatsapp_templates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('whatsapp_phone_id')->constrained()->cascadeOnDelete();
    $table->string('template_id');
    $table->string('name');
    $table->string('language')->default('pt_BR');
    $table->enum('category', ['AUTHENTICATION', 'MARKETING', 'UTILITY']);
    $table->enum('status', ['APPROVED', 'PENDING', 'REJECTED', 'DISABLED']);
    $table->json('components');                   // Header, body, footer, buttons
    $table->text('rejection_reason')->nullable();
    $table->timestamp('last_synced_at')->nullable();
    $table->timestamps();

    $table->unique(['whatsapp_phone_id', 'name', 'language']);
});
```

---

## Configuration

```php
// config/whatsapp.php
return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API Settings
    |--------------------------------------------------------------------------
    */
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    'api_base_url' => 'https://graph.facebook.com',
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),  // Global fallback token

    /*
    |--------------------------------------------------------------------------
    | Phone Defaults
    |--------------------------------------------------------------------------
    | Default values for new phones created in database.
    | Phones are managed ONLY in database (not in config).
    */
    'phone_defaults' => [
        'processing_mode' => 'batch',
        'batch_window_seconds' => 3,
        'batch_max_messages' => 10,
        'auto_download_media' => true,
        'transcription_enabled' => false,
        'allowed_message_types' => ['*'],         // All types allowed
        'on_disallowed_type' => 'ignore',
        'disallowed_type_reply' => 'This message type is not supported.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),  // For signature verification
        'path' => 'webhooks/whatsapp',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Storage
    |--------------------------------------------------------------------------
    */
    'media' => [
        'storage_disk' => env('WHATSAPP_MEDIA_DISK', 'local'),
        'storage_path' => 'whatsapp/media',
        'max_size' => 16 * 1024 * 1024,           // 16MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Transcription Services
    |--------------------------------------------------------------------------
    */
    'transcription' => [
        'default_service' => env('WHATSAPP_TRANSCRIPTION_SERVICE', 'openai'),
        'default_language' => 'pt-BR',
        'services' => [
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => 'whisper-1',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('WHATSAPP_QUEUE_CONNECTION'),
        'queue' => env('WHATSAPP_QUEUE_NAME', 'whatsapp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety Scheduler
    |--------------------------------------------------------------------------
    | Interval (in minutes) for checking stale/orphaned batches.
    */
    'stale_batch_check_interval' => 5,
];
```

---

## Message Types

### Inbound Message Types

| Type | Description | Has Media | DTO Class |
|------|-------------|-----------|-----------|
| `text` | Plain text message | No | `TextContent` |
| `image` | Image with optional caption | Yes | `ImageContent` |
| `video` | Video with optional caption | Yes | `VideoContent` |
| `audio` | Audio file or voice note | Yes | `AudioContent` |
| `document` | PDF, Excel, etc. | Yes | `DocumentContent` |
| `sticker` | Sticker | Yes | `StickerContent` |
| `location` | GPS coordinates | No | `LocationContent` |
| `contacts` | Contact card(s) | No | `ContactsContent` |
| `interactive` | Reply to buttons/list | No | `InteractiveReplyContent` |
| `button` | Quick reply button tap | No | `InteractiveReplyContent` |
| `reaction` | Emoji reaction to message | No | `ReactionContent` |
| `order` | Order from catalog | No | `OrderContent` |
| `system` | System message | No | `SystemContent` |
| `unsupported` | Unknown/unsupported type | No | `UnknownContent` |

### Content DTOs

```php
namespace Multek\LaravelWhatsAppCloud\DTOs\MessageContent;

interface MessageContentInterface
{
    public function getType(): string;
    public function toArray(): array;
}

class TextContent implements MessageContentInterface
{
    public function __construct(
        public readonly string $body,
    ) {}
}

class ImageContent implements MessageContentInterface
{
    public function __construct(
        public readonly string $mediaId,
        public readonly ?string $caption = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $sha256 = null,
    ) {}
}

class AudioContent implements MessageContentInterface
{
    public function __construct(
        public readonly string $mediaId,
        public readonly ?string $mimeType = null,
        public readonly bool $voice = false,  // true = voice note
    ) {}
}

class LocationContent implements MessageContentInterface
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?string $name = null,
        public readonly ?string $address = null,
    ) {}
}

class ContactsContent implements MessageContentInterface
{
    public function __construct(
        public readonly array $contacts,  // Array of contact objects
    ) {}
}

class InteractiveReplyContent implements MessageContentInterface
{
    public function __construct(
        public readonly string $replyType,    // 'button_reply' or 'list_reply'
        public readonly string $id,           // Button/row ID
        public readonly string $title,        // Button/row title
        public readonly ?string $description = null,
    ) {}
}

class ReactionContent implements MessageContentInterface
{
    public function __construct(
        public readonly string $messageId,    // Message being reacted to
        public readonly string $emoji,        // Reaction emoji
    ) {}
}
```

### WhatsAppMessage Model Methods

```php
class WhatsAppMessage extends Model
{
    // Get typed content DTO
    public function getTypedContent(): MessageContentInterface;

    // Type checks
    public function isText(): bool;
    public function isMedia(): bool;          // image, video, audio, document, sticker
    public function isImage(): bool;
    public function isVideo(): bool;
    public function isAudio(): bool;
    public function isVoiceNote(): bool;      // audio with voice=true
    public function isDocument(): bool;
    public function isSticker(): bool;
    public function isLocation(): bool;
    public function isContacts(): bool;
    public function isInteractiveReply(): bool;
    public function isReaction(): bool;

    // Media helpers
    public function hasMedia(): bool;
    public function getMediaPath(): ?string;
    public function getMediaUrl(): ?string;   // Signed URL if on S3

    // Transcription
    public function hasTranscription(): bool;
    public function getTranscription(): ?string;
}
```

---

## Contracts & DTOs

### MessageHandlerInterface

```php
namespace Multek\LaravelWhatsAppCloud\Contracts;

interface MessageHandlerInterface
{
    /**
     * Handle a batch of incoming messages.
     *
     * Called after all messages in batch are ready:
     * - Media downloaded to local storage
     * - Audio transcribed (if enabled)
     */
    public function handle(IncomingMessageContext $context): void;
}
```

### IncomingMessageContext

```php
namespace Multek\LaravelWhatsAppCloud\DTOs;

class IncomingMessageContext
{
    public function __construct(
        public readonly WhatsAppPhone $phone,
        public readonly WhatsAppConversation $conversation,
        public readonly WhatsAppMessageBatch $batch,
        public readonly Collection $messages,
    ) {}

    /**
     * Get combined text from all text messages in batch.
     */
    public function getTextContent(): string;

    /**
     * Get combined text including captions and transcriptions.
     */
    public function getFullTextContent(): string;

    /**
     * Get all media files with local paths.
     */
    public function getMedia(): Collection;

    /**
     * Get all audio transcriptions.
     */
    public function getTranscriptions(): Collection;

    /**
     * Get messages of specific type.
     */
    public function getMessagesByType(string $type): Collection;

    /**
     * Get location messages.
     */
    public function getLocations(): Collection;

    /**
     * Get interactive reply messages (button/list selections).
     */
    public function getInteractiveReplies(): Collection;

    /**
     * Get contact card messages.
     */
    public function getContacts(): Collection;

    /**
     * Get reaction messages.
     */
    public function getReactions(): Collection;

    /**
     * Check if batch contains specific message type.
     */
    public function hasMessageType(string $type): bool;

    /**
     * Reply with text message.
     */
    public function reply(string $text): WhatsAppMessage;

    /**
     * Get reply builder for fluent API.
     */
    public function replyWith(): MessageBuilder;
}
```

### TranscriptionServiceInterface

```php
namespace Multek\LaravelWhatsAppCloud\Contracts;

interface TranscriptionServiceInterface
{
    public function transcribe(string $audioPath, string $language = 'pt-BR'): string;
    public function supports(string $mimeType): bool;
}
```

---

## Outbound Fluent API

### Direct Methods (via Facade)

```php
use Multek\LaravelWhatsAppCloud\Facades\WhatsApp;

// Text
WhatsApp::sendText($to, 'Hello!');
WhatsApp::sendText($to, 'Check this: https://example.com', previewUrl: true);

// Media
WhatsApp::sendImage($to, $url, caption: 'Check this');
WhatsApp::sendVideo($to, $url, caption: 'Demo video');
WhatsApp::sendAudio($to, $url);
WhatsApp::sendDocument($to, $url, filename: 'report.pdf', caption: 'Monthly report');
WhatsApp::sendSticker($to, $stickerUrl);

// Location
WhatsApp::sendLocation($to, lat: -23.55, lng: -46.63, name: 'Office', address: 'Av. Paulista, 1000');

// Contacts
WhatsApp::sendContacts($to, [
    [
        'name' => ['formatted_name' => 'John Doe'],
        'phones' => [['phone' => '+5511999999999']],
    ],
]);

// Reaction
WhatsApp::sendReaction($messageId, '=M');
WhatsApp::removeReaction($messageId);  // Empty emoji removes reaction

// Read receipt
WhatsApp::markAsRead($messageId);

// Using specific phone
WhatsApp::phone('sales')->sendText($to, 'Hello from sales!');
```

### Builder Pattern

```php
// Text with preview
WhatsApp::to($to)
    ->text('Check this link: https://example.com')
    ->previewUrl()
    ->send();

// Image with caption
WhatsApp::to($to)
    ->image($imageUrl)
    ->caption('Product photo')
    ->send();

// Document
WhatsApp::to($to)
    ->document($pdfUrl)
    ->filename('invoice.pdf')
    ->caption('Your invoice')
    ->send();

// Template with parameters
WhatsApp::to($to)
    ->template('order_confirmation')
    ->language('pt_BR')
    ->headerImage($productImageUrl)
    ->bodyParameters(['John', 'R$ 150,00', '12345'])
    ->buttonParameters(['https://track.example.com/12345'])
    ->send();

// Interactive Buttons (max 3 buttons)
WhatsApp::to($to)
    ->buttons('Would you like to proceed?')
    ->header('Order Confirmation')
    ->footer('Reply within 24 hours')
    ->button('confirm', 'Yes, Confirm')
    ->button('cancel', 'No, Cancel')
    ->button('help', 'Need Help')
    ->send();

// Interactive List
WhatsApp::to($to)
    ->list('Choose a product category:', 'View Categories')
    ->section('Electronics', [
        ['id' => 'phones', 'title' => 'Smartphones', 'description' => 'Latest models'],
        ['id' => 'laptops', 'title' => 'Laptops', 'description' => 'For work and gaming'],
    ])
    ->section('Home', [
        ['id' => 'furniture', 'title' => 'Furniture'],
        ['id' => 'appliances', 'title' => 'Appliances'],
    ])
    ->send();

// CTA URL Button
WhatsApp::to($to)
    ->ctaUrl('Visit our store for exclusive deals!')
    ->buttonText('Shop Now')
    ->url('https://shop.example.com')
    ->send();

// Queue message instead of sending immediately
WhatsApp::to($to)
    ->text('This will be queued')
    ->queue();

// Using specific phone
WhatsApp::phone('support')
    ->to($to)
    ->text('Hello from support!')
    ->send();
```

### MessageBuilder Class

```php
namespace Multek\LaravelWhatsAppCloud\Support;

class MessageBuilder
{
    // Targeting
    public function to(string $phone): self;

    // Text
    public function text(string $body): self;
    public function previewUrl(bool $preview = true): self;

    // Media
    public function image(string $urlOrMediaId): self;
    public function video(string $urlOrMediaId): self;
    public function audio(string $urlOrMediaId): self;
    public function document(string $urlOrMediaId): self;
    public function sticker(string $urlOrMediaId): self;
    public function caption(string $caption): self;
    public function filename(string $filename): self;

    // Location
    public function location(float $lat, float $lng, ?string $name = null, ?string $address = null): self;

    // Template
    public function template(string $name): self;
    public function language(string $code): self;
    public function headerImage(string $url): self;
    public function headerVideo(string $url): self;
    public function headerDocument(string $url): self;
    public function headerText(string $text): self;
    public function bodyParameters(array $params): self;
    public function buttonParameters(array $params): self;

    // Interactive Buttons
    public function buttons(string $body): self;
    public function button(string $id, string $title): self;

    // Interactive List
    public function list(string $body, string $buttonText): self;
    public function section(string $title, array $rows): self;

    // CTA URL
    public function ctaUrl(string $body): self;
    public function buttonText(string $text): self;
    public function url(string $url): self;

    // Common interactive
    public function header(string $text): self;
    public function footer(string $text): self;

    // Actions
    public function send(): WhatsAppMessage;
    public function queue(): WhatsAppMessage;
}
```

---

## Jobs

### WhatsAppProcessIncomingMessage

Entry point for processing incoming messages. Creates/updates batch and dispatches media jobs.

```php
namespace Multek\LaravelWhatsAppCloud\Jobs;

class WhatsAppProcessIncomingMessage implements ShouldQueue
{
    public function __construct(
        public WhatsAppMessage $message
    ) {}

    public function handle(): void
    {
        $message = $this->message;
        $phone = $message->phone;

        // Find or create batch for this conversation (with locking)
        $batch = DB::transaction(function () use ($message) {
            return WhatsAppMessageBatch::lockForUpdate()
                ->where('whatsapp_conversation_id', $message->whatsapp_conversation_id)
                ->where('status', 'collecting')
                ->first()
                ?? WhatsAppMessageBatch::create([...]);
        });

        $message->update(['whatsapp_message_batch_id' => $batch->id]);

        // Update batch window
        $processAfter = now()->addSeconds($phone->batch_window_seconds);
        $batch->update(['process_after' => $processAfter]);

        // Dispatch delayed job to check if batch is ready
        WhatsAppCheckBatchReady::dispatch($batch)
            ->delay($processAfter->addSecond());

        // Handle media
        if ($message->hasMedia() && $phone->auto_download_media) {
            $message->update(['status' => 'processing', 'media_status' => 'pending']);
            WhatsAppDownloadMedia::dispatch($message);
        } else {
            $message->markAsReady();
        }
    }
}
```

### WhatsAppDownloadMedia

Downloads media from WhatsApp API before URL expires (~5 minutes).

```php
class WhatsAppDownloadMedia implements ShouldQueue
{
    public $tries = 3;
    public $backoff = [10, 30, 60];

    public function handle(MediaService $mediaService): void
    {
        $message = $this->message;
        $message->update(['media_status' => 'downloading']);

        try {
            $path = $mediaService->download($message);

            $message->update([
                'media_status' => 'downloaded',
                'local_media_path' => $path,
            ]);

            // If audio and transcription enabled, transcribe
            if ($message->isAudio() && $message->phone->transcription_enabled) {
                $message->update(['transcription_status' => 'pending']);
                WhatsAppTranscribeAudio::dispatch($message);
            } else {
                $message->markAsReady();
            }
        } catch (Exception $e) {
            $message->update([
                'media_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

### WhatsAppTranscribeAudio

Transcribes audio files using configured service.

```php
class WhatsAppTranscribeAudio implements ShouldQueue
{
    public $tries = 2;
    public $backoff = [30, 60];

    public function handle(TranscriptionService $transcriptionService): void
    {
        $message = $this->message;
        $message->update(['transcription_status' => 'transcribing']);

        try {
            $text = $transcriptionService->transcribe(
                Storage::disk($message->local_media_disk)->path($message->local_media_path),
                $message->phone->transcription_language ?? 'pt-BR'
            );

            $message->update([
                'transcription_status' => 'transcribed',
                'transcription' => $text,
            ]);

            $message->markAsReady();

            event(new AudioTranscribed($message));
        } catch (Exception $e) {
            $message->update([
                'transcription_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            // Still mark as ready - handler can work without transcription
            $message->markAsReady();
        }
    }
}
```

### WhatsAppCheckBatchReady

Delayed job that checks if a batch should be processed.

```php
class WhatsAppCheckBatchReady implements ShouldQueue
{
    public function handle(): void
    {
        $batch = $this->batch->fresh();

        // Skip if already processed
        if ($batch->status !== 'collecting') {
            return;
        }

        // Check if should process
        if ($batch->shouldProcess()) {
            WhatsAppProcessBatch::dispatch($batch);
        }
        // If not ready, another delayed job was dispatched by a newer message
    }
}
```

### WhatsAppProcessBatch

Calls the configured handler with all batch messages.

```php
class WhatsAppProcessBatch implements ShouldQueue
{
    public function handle(): void
    {
        $batch = $this->batch;

        // Prevent double processing
        if ($batch->status !== 'collecting') {
            return;
        }

        $batch->update(['status' => 'processing']);

        try {
            $phone = $batch->phone;
            $conversation = $batch->conversation;
            $messages = $batch->messages()->where('status', 'ready')->get();

            // Build context
            $context = new IncomingMessageContext(
                phone: $phone,
                conversation: $conversation,
                batch: $batch,
                messages: $messages,
            );

            // Instantiate and call handler
            if ($phone->handler) {
                $handler = app($phone->handler);
                $handler->handle($context);
            }

            // Mark all messages as processed
            $messages->each->update(['status' => 'processed']);

            $batch->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            event(new BatchProcessed($batch, $context));

        } catch (Exception $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            report($e);
        }
    }
}
```

### WhatsAppCheckStaleBatches

Safety scheduler that runs every 5 minutes to catch orphaned batches.

```php
class WhatsAppCheckStaleBatches implements ShouldQueue
{
    public function handle(): void
    {
        // Find batches that should have been processed
        $staleBatches = WhatsAppMessageBatch::query()
            ->where('status', 'collecting')
            ->where('process_after', '<', now()->subSeconds(30))
            ->get();

        foreach ($staleBatches as $batch) {
            if ($batch->shouldProcess()) {
                WhatsAppProcessBatch::dispatch($batch);
            }
        }
    }
}
```

---

## Events

| Event | Description | Payload |
|-------|-------------|---------|
| `MessageReceived` | Webhook received new message | `WhatsAppMessage` |
| `MessageFiltered` | Message type was not allowed | `WhatsAppMessage`, `reason` |
| `MessageReady` | Message ready for batch | `WhatsAppMessage` |
| `BatchReady` | All messages in batch ready | `WhatsAppMessageBatch` |
| `BatchProcessed` | Handler finished processing | `WhatsAppMessageBatch`, `IncomingMessageContext` |
| `MessageSent` | Outbound message sent | `WhatsAppMessage` |
| `MessageDelivered` | Message delivered to device | `WhatsAppMessage` |
| `MessageRead` | Message was read | `WhatsAppMessage` |
| `MessageFailed` | Send/delivery failed | `WhatsAppMessage`, `error` |
| `MediaDownloaded` | Media file downloaded | `WhatsAppMessage` |
| `AudioTranscribed` | Audio transcription complete | `WhatsAppMessage` |

---

## Console Commands

```bash
# Install package (publish config, run migrations)
php artisan whatsapp:install

# Sync templates from Meta
php artisan whatsapp:sync-templates
php artisan whatsapp:sync-templates --phone=support

# Process stale batches (runs via scheduler)
php artisan whatsapp:process-stale-batches
```

### Scheduler Registration

```php
// app/Console/Kernel.php or routes/console.php (Laravel 11)
Schedule::command('whatsapp:process-stale-batches')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

---

## Directory Structure

```
laravel-whatsapp-cloud/
   config/
      whatsapp.php
   database/migrations/
      2024_01_01_000001_create_whatsapp_phones_table.php
      2024_01_01_000002_create_whatsapp_conversations_table.php
      2024_01_01_000003_create_whatsapp_message_batches_table.php
      2024_01_01_000004_create_whatsapp_messages_table.php
      2024_01_01_000005_create_whatsapp_templates_table.php
   routes/
      webhooks.php
   src/
      Client/
         WhatsAppClient.php
         WhatsAppClientInterface.php
      Console/Commands/
         InstallCommand.php
         SyncTemplatesCommand.php
         ProcessStaleBatchesCommand.php
      Contracts/
         MessageHandlerInterface.php
         TranscriptionServiceInterface.php
         MediaStorageInterface.php
      DTOs/
         IncomingMessageContext.php
         MessageContent/
             MessageContentInterface.php
             TextContent.php
             ImageContent.php
             VideoContent.php
             AudioContent.php
             DocumentContent.php
             StickerContent.php
             LocationContent.php
             ContactsContent.php
             InteractiveReplyContent.php
             ReactionContent.php
             UnknownContent.php
      Events/
         MessageReceived.php
         MessageFiltered.php
         MessageReady.php
         BatchReady.php
         BatchProcessed.php
         MessageSent.php
         MessageDelivered.php
         MessageRead.php
         MessageFailed.php
         MediaDownloaded.php
         AudioTranscribed.php
      Exceptions/
         WhatsAppException.php
         InvalidPhoneException.php
         MessageSendException.php
         WebhookVerificationException.php
         MediaDownloadException.php
         TranscriptionException.php
         HandlerException.php
      Facades/
         WhatsApp.php
      Http/
         Controllers/
            WebhookController.php
         Middleware/
             VerifyWhatsAppSignature.php
      Jobs/
         WhatsAppProcessIncomingMessage.php
         WhatsAppDownloadMedia.php
         WhatsAppTranscribeAudio.php
         WhatsAppCheckBatchReady.php
         WhatsAppProcessBatch.php
         WhatsAppCheckStaleBatches.php
         WhatsAppSendMessage.php
         WhatsAppSyncTemplates.php
      Models/
         WhatsAppPhone.php
         WhatsAppConversation.php
         WhatsAppMessageBatch.php
         WhatsAppMessage.php
         WhatsAppTemplate.php
      Services/
         MediaService.php
         TranscriptionService.php
         Transcription/
             OpenAITranscriber.php
             WhisperTranscriber.php
      Support/
         MessageBuilder.php
         TemplateBuilder.php
         WebhookProcessor.php
      WhatsAppManager.php
      WhatsAppServiceProvider.php
   tests/
      Feature/
         WebhookHandlingTest.php
         MessageProcessingPipelineTest.php
         BatchProcessingTest.php
         HandlerDispatchTest.php
      Unit/
         WhatsAppClientTest.php
         MediaServiceTest.php
         TranscriptionServiceTest.php
         MessageBuilderTest.php
      Pest.php
      TestCase.php
   composer.json
   phpunit.xml
   pint.json
   phpstan.neon
   README.md
   CHANGELOG.md
   LICENSE
```

---

## Usage Example

### 1. Install the Package

```bash
composer require multek/laravel-whatsapp-cloud
php artisan whatsapp:install
php artisan migrate
```

### 2. Configure Environment

```env
WHATSAPP_ACCESS_TOKEN=your_meta_access_token
WHATSAPP_WEBHOOK_VERIFY_TOKEN=your_verify_token
WHATSAPP_APP_SECRET=your_app_secret
WHATSAPP_MEDIA_DISK=s3
WHATSAPP_TRANSCRIPTION_SERVICE=openai
OPENAI_API_KEY=your_openai_key
```

### 3. Create a Handler

```php
namespace App\WhatsApp\Handlers;

use Multek\LaravelWhatsAppCloud\Contracts\MessageHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\IncomingMessageContext;
use Multek\LaravelWhatsAppCloud\Facades\WhatsApp;

class SupportAgentHandler implements MessageHandlerInterface
{
    public function __construct(
        private AIAgentService $aiAgent
    ) {}

    public function handle(IncomingMessageContext $context): void
    {
        // All messages in batch, media downloaded, audio transcribed
        $fullText = $context->getFullTextContent();
        $mediaFiles = $context->getMedia();

        // Check for interactive replies
        if ($context->hasMessageType('interactive')) {
            $replies = $context->getInteractiveReplies();
            // Handle button/list selection
        }

        // Check for location
        if ($context->hasMessageType('location')) {
            $locations = $context->getLocations();
            // Handle location sharing
        }

        // Call your AI agent
        $response = $this->aiAgent->process(
            text: $fullText,
            media: $mediaFiles,
            conversation: $context->conversation,
        );

        // Reply via WhatsApp
        $context->reply($response);

        // Or use fluent API for rich messages
        $context->replyWith()
            ->buttons('Was this helpful?')
            ->button('yes', 'Yes, thanks!')
            ->button('no', 'No, connect me to human')
            ->send();
    }
}
```

### 4. Register Phone in Database

```php
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

WhatsAppPhone::create([
    'key' => 'support',
    'phone_id' => '123456789012345',
    'phone_number' => '+5511999999999',
    'business_account_id' => 'your_business_account_id',
    'display_name' => 'Support Line',

    // Handler
    'handler' => \App\WhatsApp\Handlers\SupportAgentHandler::class,
    'handler_config' => ['ai_model' => 'gpt-4'],

    // Batch processing for AI workflows
    'processing_mode' => 'batch',
    'batch_window_seconds' => 3,
    'batch_max_messages' => 10,

    // Media & transcription
    'auto_download_media' => true,
    'transcription_enabled' => true,
    'transcription_service' => 'openai',

    // Only allow these message types
    'allowed_message_types' => ['text', 'image', 'audio', 'document', 'interactive'],
    'on_disallowed_type' => 'auto_reply',
    'disallowed_type_reply' => 'Please send text, images, documents, or voice messages.',
]);
```

### 5. Send Outbound Messages

```php
use Multek\LaravelWhatsAppCloud\Facades\WhatsApp;

// Simple text
WhatsApp::phone('support')->sendText('+5511888888888', 'Hello!');

// Template
WhatsApp::phone('notifications')
    ->to('+5511888888888')
    ->template('order_shipped')
    ->language('pt_BR')
    ->bodyParameters(['John', '12345', 'Tomorrow'])
    ->send();

// Interactive buttons
WhatsApp::phone('support')
    ->to('+5511888888888')
    ->buttons('How can we help you today?')
    ->button('sales', 'Sales')
    ->button('support', 'Technical Support')
    ->button('billing', 'Billing')
    ->send();
```

---

## Key Design Decisions

1. **Database-only phones** - No config-based phones. All phone numbers managed in database for dynamic management and multi-tenant support.

2. **Delayed jobs for batch processing** - Each message dispatches a delayed `WhatsAppCheckBatchReady` job instead of relying on frequent scheduler.

3. **Safety scheduler every 5 minutes** - Catches orphaned batches, not primary processing mechanism.

4. **Per-conversation batching** - Batches are scoped to conversation, not phone. Different customers on same phone get separate batches.

5. **Download media immediately** - WhatsApp media URLs expire in ~5 minutes. Download happens before batch processing.

6. **Transcription before handler** - Audio is transcribed as part of pipeline, so handler receives ready-to-use text.

7. **WhatsApp-prefixed jobs** - All jobs prefixed with `WhatsApp` for clarity in Laravel Horizon and queue monitoring.

8. **Database locking** - Use `lockForUpdate()` when creating/updating batches to prevent race conditions from concurrent webhooks.

---

## API Client Methods

### WhatsAppClient

```php
namespace Multek\LaravelWhatsAppCloud\Client;

interface WhatsAppClientInterface
{
    // Text Messages
    public function sendText(string $to, string $message, bool $previewUrl = false): array;

    // Media Messages
    public function sendImage(string $to, string $urlOrMediaId, ?string $caption = null): array;
    public function sendVideo(string $to, string $urlOrMediaId, ?string $caption = null): array;
    public function sendAudio(string $to, string $urlOrMediaId): array;
    public function sendDocument(string $to, string $urlOrMediaId, ?string $filename = null, ?string $caption = null): array;
    public function sendSticker(string $to, string $urlOrMediaId): array;

    // Template Messages
    public function sendTemplate(string $to, string $templateName, array $components = [], string $language = 'pt_BR'): array;

    // Interactive Messages
    public function sendButtons(string $to, string $body, array $buttons, ?string $header = null, ?string $footer = null): array;
    public function sendList(string $to, string $body, string $buttonText, array $sections, ?string $header = null, ?string $footer = null): array;
    public function sendCtaUrl(string $to, string $body, string $buttonText, string $url, ?string $header = null, ?string $footer = null): array;

    // Location
    public function sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null): array;

    // Contacts
    public function sendContacts(string $to, array $contacts): array;

    // Reactions
    public function sendReaction(string $messageId, string $emoji): array;
    public function removeReaction(string $messageId): array;

    // Read Receipts
    public function markAsRead(string $messageId): array;

    // Media Management
    public function uploadMedia(string $filePath, string $mimeType): array;
    public function getMediaUrl(string $mediaId): string;
    public function downloadMedia(string $mediaId): string;
    public function deleteMedia(string $mediaId): bool;

    // Templates Management
    public function getTemplates(?string $status = null): array;
    public function getTemplate(string $templateName): array;

    // Phone Number Info
    public function getPhoneNumberInfo(): array;
}
```

---

## Testing

### Test Structure

```php
// tests/Feature/WebhookHandlingTest.php
it('verifies webhook subscription', function () {
    $response = $this->get('/webhooks/whatsapp?' . http_build_query([
        'hub.mode' => 'subscribe',
        'hub.verify_token' => config('whatsapp.webhook.verify_token'),
        'hub.challenge' => 'test_challenge',
    ]));

    $response->assertOk();
    $response->assertSee('test_challenge');
});

it('processes incoming text message', function () {
    $payload = getWebhookPayload('text_message');

    $response = $this->postJson('/webhooks/whatsapp', $payload, [
        'X-Hub-Signature-256' => generateSignature($payload),
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('whatsapp_messages', [
        'type' => 'text',
        'direction' => 'inbound',
    ]);
});

// tests/Feature/BatchProcessingTest.php
it('batches multiple messages from same contact', function () {
    // Send 3 messages in quick succession
    $this->postWebhook(textMessage('Hello'));
    $this->postWebhook(imageMessage());
    $this->postWebhook(audioMessage());

    // Should create single batch with 3 messages
    $this->assertDatabaseCount('whatsapp_message_batches', 1);
    $this->assertDatabaseCount('whatsapp_messages', 3);
});

it('calls handler when batch is ready', function () {
    $handler = Mockery::mock(MessageHandlerInterface::class);
    $handler->shouldReceive('handle')->once();
    $this->app->instance(SupportHandler::class, $handler);

    // Send message and process batch
    $this->postWebhook(textMessage('Hello'));
    $this->travel(5)->seconds();
    Artisan::call('queue:work', ['--once' => true]);

    // Handler should have been called
});
```

---

## Composer Configuration

```json
{
    "name": "multek/laravel-whatsapp-cloud",
    "description": "Enterprise-grade WhatsApp Cloud API integration for Laravel with multi-phone support, batch processing, and AI agent workflows.",
    "keywords": ["laravel", "whatsapp", "cloud-api", "messaging", "ai-agent"],
    "license": "MIT",
    "authors": [
        {
            "name": "Multek",
            "email": "dev@multek.com"
        }
    ],
    "require": {
        "php": "^8.2",
        "illuminate/support": "^11.0",
        "illuminate/http": "^11.0",
        "illuminate/database": "^11.0",
        "illuminate/queue": "^11.0"
    },
    "require-dev": {
        "orchestra/testbench": "^9.0",
        "pestphp/pest": "^2.0",
        "pestphp/pest-plugin-laravel": "^2.0",
        "laravel/pint": "^1.0",
        "phpstan/phpstan": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Multek\\LaravelWhatsAppCloud\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Multek\\LaravelWhatsAppCloud\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Multek\\LaravelWhatsAppCloud\\WhatsAppServiceProvider"
            ],
            "aliases": {
                "WhatsApp": "Multek\\LaravelWhatsAppCloud\\Facades\\WhatsApp"
            }
        }
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```


 Plan Summary

  Implementation Order (13 Steps)

  | Step | What                   | Key Files
           |
  |------|------------------------|---------------------------------------------------------
  ---------|
  | 1    | Package Foundation     | composer.json, WhatsAppServiceProvider.php,
  Facades/WhatsApp.php |
  | 2    | Database Layer         | 5 migrations + 5 models
           |
  | 3    | Contracts & Exceptions | MessageHandlerInterface, IncomingMessageContext, 7
  exceptions    |
  | 3b   | Message Type DTOs      | 12 content DTOs (TextContent, ImageContent, etc.)
           |
  | 3c   | Outbound Fluent API    | MessageBuilder, direct methods
           |
  | 4    | Services               | MediaService, TranscriptionService
           |
  | 5    | API Client             | WhatsAppClient with all send methods
           |
  | 6    | Manager & Builders     | WhatsAppManager, TemplateBuilder
           |
  | 7    | Webhook System         | Controller, middleware, routes
           |
  | 8    | Jobs                   | 8 WhatsApp-prefixed jobs for async pipeline
           |
  | 9    | Events                 | 11 events
           |
  | 10   | Console Commands       | install, sync-templates, process-stale-batches
           |
  | 11   | Configuration          | config/whatsapp.php
           |
  | 12   | Tests                  | Unit + Feature tests with Pest
           |
  | 13   | Documentation & CI     | README, phpunit.xml, pint.json, GitHub Actions