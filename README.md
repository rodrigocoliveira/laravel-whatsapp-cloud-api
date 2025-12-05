# Laravel WhatsApp Cloud API

A comprehensive Laravel package for integrating with the WhatsApp Cloud API. Supports sending and receiving messages, media handling, batch processing for AI agents, and multi-phone configurations.

## Features

- **Multi-Phone Support**: Manage multiple WhatsApp phone numbers with independent handlers
- **All Message Types**: Text, images, videos, audio, documents, stickers, locations, contacts, interactive buttons/lists, templates
- **Batch Processing**: Collect messages in time windows before processing (ideal for AI chatbots)
- **Media Handling**: Automatic download and storage of media files
- **Audio Transcription**: Built-in OpenAI Whisper integration for voice messages
- **Webhook Security**: HMAC-SHA256 signature verification
- **Event-Driven**: Observable events for all major operations
- **Queue Support**: Fully async processing pipeline with configurable queues

## Requirements

- PHP 8.2+
- Laravel 11.0+

## Installation

```bash
composer require multek/laravel-whatsapp-cloud-api
```

Run the installation command:

```bash
php artisan whatsapp:install
```

This will publish the configuration file and migrations.

Run the migrations:

```bash
php artisan migrate
```

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
WHATSAPP_ACCESS_TOKEN=your_meta_access_token
WHATSAPP_WEBHOOK_VERIFY_TOKEN=your_webhook_verify_token
WHATSAPP_APP_SECRET=your_app_secret

# Optional: for audio transcription
OPENAI_API_KEY=your_openai_api_key

# Optional: queue settings
WHATSAPP_QUEUE_CONNECTION=redis
WHATSAPP_QUEUE_NAME=whatsapp
```

### Creating a Phone Configuration

Create a phone record in the database:

```php
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

WhatsAppPhone::create([
    'key' => 'support',
    'phone_id' => 'your_meta_phone_number_id',
    'phone_number' => '+5511999999999',
    'business_account_id' => 'your_waba_id',
    'access_token' => null, // Uses default from config if null
    'handler' => \App\WhatsApp\Handlers\SupportHandler::class,
    'processing_mode' => 'batch', // or 'immediate'
    'batch_window_seconds' => 3,
    'auto_download_media' => true,
    'transcription_enabled' => true,
]);
```

### Webhook Setup

Configure your webhook URL in the Meta Developer Portal:

```
https://yourdomain.com/webhooks/whatsapp
```

The package handles both verification (GET) and incoming events (POST).

### Webhook Logging

All incoming webhook payloads are automatically stored in the `whatsapp_webhook_logs` table for debugging and auditing purposes. This helps you:

- Debug issues by inspecting the exact payload Meta sent
- Audit and replay webhooks if processing fails
- Analyze edge cases in payload structures

Configure retention in your `.env`:

```env
WHATSAPP_WEBHOOK_LOG_RETENTION_DAYS=30  # Default: 30 days
```

To prune old logs, add this to your `app/Console/Kernel.php` scheduler:

```php
use Multek\LaravelWhatsAppCloud\Models\WhatsAppWebhookLog;

protected function schedule(Schedule $schedule): void
{
    $schedule->command('model:prune', [
        '--model' => [WhatsAppWebhookLog::class],
    ])->daily();
}
```

Or run manually:

```bash
php artisan model:prune --model="Multek\LaravelWhatsAppCloud\Models\WhatsAppWebhookLog"
```

## Usage

### Sending Messages

```php
use Multek\LaravelWhatsAppCloud\Facades\WhatsApp;

// Send a text message
WhatsApp::phone('support')->sendText('+5511999999999', 'Hello!');

// Send with fluent builder
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->text('Hello World!')
    ->send();

// Send an image
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->image('https://example.com/image.jpg')
    ->caption('Check this out!')
    ->send();

// Send a document
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->document('https://example.com/file.pdf')
    ->filename('report.pdf')
    ->send();

// Send interactive buttons
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->interactive()
    ->body('Please choose an option:')
    ->button('btn_yes', 'Yes')
    ->button('btn_no', 'No')
    ->send();

// Send a location
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->location(-23.5505, -46.6333)
    ->name('Sao Paulo')
    ->address('Sao Paulo, Brazil')
    ->send();
```

### Creating a Message Handler

Create a handler class that implements `MessageHandlerInterface`:

```php
namespace App\WhatsApp\Handlers;

use Multek\LaravelWhatsAppCloud\Contracts\MessageHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\IncomingMessageContext;

class SupportHandler implements MessageHandlerInterface
{
    public function handle(IncomingMessageContext $context): void
    {

        // Check for processing errors (failed media downloads or transcriptions)
        if ($context->hasFailedMediaDownloads()) {
            $context->reply(__('whatsapp.media_download_failed'));
            return;
        }

        if ($context->hasFailedTranscriptions()) {
            $context->reply(__('whatsapp.transcription_failed'));
            return;
        }

        // Or check for any processing error generically
        if ($context->hasProcessingErrors()) {
            foreach ($context->getProcessingErrors() as $message) {
                Log::warning('Processing error', [
                    'message_id' => $message->id,
                    'error' => $message->error_message,
                ]);
            }
            $context->reply(__('whatsapp.processing_error'));
            return;
        }

        // Get text content from all messages in the batch
        $textContent = $context->getTextContent();

        // Get media files (already downloaded)
        $mediaMessages = $context->getMedia();

        // Get audio transcriptions
        $transcriptions = $context->getTranscriptions();

        // Access the conversation
        $conversation = $context->conversation;
        $contactPhone = $conversation->contact_phone;

        // Reply to the user
        $context->reply('Thanks for your message! We will get back to you soon.');

        // Or use the fluent builder for complex replies
        $context->replyWith()
            ->text('Here is your summary:')
            ->send();
    }
}
```

### Working with Message Content

Each message type has a typed DTO accessible via `getTypedContent()`:

```php
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\TextContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\ImageContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\LocationContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\InteractiveReplyContent;

foreach ($context->messages as $message) {
    $content = $message->getTypedContent();

    if ($content instanceof TextContent) {
        $text = $content->body;
    }

    if ($content instanceof ImageContent) {
        $mediaId = $content->mediaId;
        $caption = $content->caption;
        $localPath = $message->local_media_path;
    }

    if ($content instanceof LocationContent) {
        $lat = $content->latitude;
        $lng = $content->longitude;
        $name = $content->name;
    }

    if ($content instanceof InteractiveReplyContent) {
        $buttonId = $content->id;
        $buttonTitle = $content->title;
    }
}
```

### Listening to Events

```php
use Multek\LaravelWhatsAppCloud\Events\MessageReceived;
use Multek\LaravelWhatsAppCloud\Events\BatchProcessed;
use Multek\LaravelWhatsAppCloud\Events\MediaDownloaded;

// In your EventServiceProvider or using Event facade
Event::listen(MessageReceived::class, function (MessageReceived $event) {
    Log::info('New message from: ' . $event->message->from);
});

Event::listen(BatchProcessed::class, function (BatchProcessed $event) {
    Log::info('Batch processed with ' . $event->batch->messages->count() . ' messages');
});

Event::listen(MediaDownloaded::class, function (MediaDownloaded $event) {
    Log::info('Media saved to: ' . $event->message->local_media_path);
});
```

### Available Events

| Event | Description |
|-------|-------------|
| `MessageReceived` | When a message arrives at webhook |
| `MessageFiltered` | When a message type is not allowed |
| `MessageReady` | When media/transcription complete |
| `BatchReady` | When batch is about to be processed |
| `BatchProcessed` | After handler completes |
| `MessageSent` | When outbound message is sent |
| `MessageDelivered` | When message is delivered |
| `MessageRead` | When message is read |
| `MessageFailed` | When message send fails |
| `MediaDownloaded` | After media saved locally |
| `AudioTranscribed` | After audio transcribed |

## Processing Modes

### Batch Mode (Default)

Messages are collected in a time window before being processed together. Ideal for AI chatbots that need context from multiple messages.

```php
'processing_mode' => 'batch',
'batch_window_seconds' => 3,  // Wait 3 seconds after last message
'batch_max_messages' => 10,   // Process after 10 messages regardless of time
```

### Immediate Mode

Each message is processed immediately as it arrives.

```php
'processing_mode' => 'immediate',
```

## Batch Processing Architecture

Understanding how messages flow through the system helps configure it correctly.

### Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                           MESSAGE PROCESSING FLOW                               │
└─────────────────────────────────────────────────────────────────────────────────┘

  WhatsApp User                        Your Server
       │
       │  Sends message (text, audio, image, etc.)
       ▼
┌──────────────┐
│   Meta API   │──────────────────────────────────────────────────────────────────┐
└──────────────┘                                                                  │
                                                                                  ▼
                                                                    ┌──────────────────────┐
                                                                    │  WebhookController   │
                                                                    │  (validates sig)     │
                                                                    └──────────┬───────────┘
                                                                               │
                                                                               ▼
                                                                    ┌──────────────────────┐
                                                                    │  WebhookProcessor    │
                                                                    │  • Creates Message   │
                                                                    │  • Creates Convo     │
                                                                    └──────────┬───────────┘
                                                                               │
                                                                               ▼
                                                              ┌────────────────────────────────┐
                                                              │ WhatsAppProcessIncomingMessage │
                                                              │ (Job - runs async on queue)    │
                                                              └────────────────┬───────────────┘
                                                                               │
                                              ┌────────────────────────────────┴────────────────────────────────┐
                                              │                                                                 │
                                              ▼                                                                 ▼
                                    ┌───────────────────┐                                             ┌───────────────────┐
                                    │  IMMEDIATE MODE   │                                             │    BATCH MODE     │
                                    └─────────┬─────────┘                                             └─────────┬─────────┘
                                              │                                                                 │
                                              │                                                                 ▼
                                              │                                                   ┌───────────────────────────┐
                                              │                                                   │ Find/Create Batch         │
                                              │                                                   │ • Atomic transaction      │
                                              │                                                   │ • Lock for update         │
                                              │                                                   │ • Set process_after       │
                                              │                                                   └─────────────┬─────────────┘
                                              │                                                                 │
                                              └─────────────────────────┬───────────────────────────────────────┘
                                                                        │
                                                           ┌────────────┴────────────┐
                                                           │                         │
                                                           ▼                         ▼
                                                   ┌───────────────┐         ┌───────────────┐
                                                   │  Has Media?   │         │   No Media    │
                                                   │     YES       │         │               │
                                                   └───────┬───────┘         └───────┬───────┘
                                                           │                         │
                                                           ▼                         │
                                              ┌────────────────────────┐             │
                                              │ WhatsAppDownloadMedia  │             │
                                              │ (Job - downloads file) │             │
                                              └────────────┬───────────┘             │
                                                           │                         │
                                              ┌────────────┴────────────┐            │
                                              │                         │            │
                                              ▼                         ▼            │
                                      ┌───────────────┐         ┌───────────────┐    │
                                      │ Audio + Trans │         │  Other Media  │    │
                                      │   Enabled?    │         │   or Failed   │    │
                                      └───────┬───────┘         └───────┬───────┘    │
                                              │                         │            │
                                              ▼                         │            │
                                 ┌─────────────────────────┐            │            │
                                 │ WhatsAppTranscribeAudio │            │            │
                                 │ (Job - calls OpenAI)    │            │            │
                                 └────────────┬────────────┘            │            │
                                              │                         │            │
                                              └────────────┬────────────┴────────────┘
                                                           │
                                                           ▼
                                              ┌────────────────────────┐
                                              │   message.markAsReady  │
                                              │   status = 'ready'     │
                                              └────────────┬───────────┘
                                                           │
                                                           ▼
                                              ┌────────────────────────┐
                                              │ WhatsAppCheckBatchReady│◄─────────── Scheduled check
                                              │ • All messages ready?  │             (process_after + 1s)
                                              │ • Window elapsed?      │
                                              │ • Max messages?        │
                                              └────────────┬───────────┘
                                                           │
                                          ┌────────────────┴────────────────┐
                                          │                                 │
                                          ▼                                 ▼
                                  ┌───────────────┐                 ┌───────────────┐
                                  │  NOT READY    │                 │    READY!     │
                                  │ (still proc.) │                 │               │
                                  └───────┬───────┘                 └───────┬───────┘
                                          │                                 │
                                          ▼                                 ▼
                                ┌──────────────────┐             ┌──────────────────────┐
                                │ Re-check in 5s   │             │  WhatsAppProcessBatch │
                                │ (max 10 min)     │             │  • Chronological lock │
                                └──────────────────┘             │  • Calls your Handler │
                                                                 └──────────┬───────────┘
                                                                            │
                                                                            ▼
                                                                 ┌──────────────────────┐
                                                                 │  YourHandler::handle │
                                                                 │  (your business code)│
                                                                 └──────────────────────┘
```

### Key Concepts

**Batch Window** (`batch_window_seconds`): Time to wait after the *last* message before processing. Each new message resets this timer, allowing users to send multiple messages that get grouped together.

**Max Window** (`batch_max_window_seconds`): Maximum total time a batch can stay open. Prevents infinite extension when users keep sending messages. After this time, the batch processes regardless of new messages.

**Process After**: The timestamp when a batch becomes eligible for processing. This is the *minimum* wait time - if messages are still downloading/transcribing, the batch waits until they're ready.

**Message Status Flow**:
```
received → processing → ready → processed
              │
              └──► (downloading media / transcribing audio)
```

**Batch Status Flow**:
```
collecting → processing → completed
                │
                └──► failed (timeout or error)
```

### Timing Configuration

| Config | Default | Description |
|--------|---------|-------------|
| `batch_window_seconds` | `3` | Seconds to wait after last message. Resets with each new message. |
| `batch_max_window_seconds` | `30` | Maximum seconds a batch can stay open. Hard limit. |
| `batch_max_messages` | `10` | Process immediately when this many messages are collected. |

### Safety Mechanisms

**1. Atomic Batch Creation**: Batch creation and message association happen in a database transaction with row locking, preventing race conditions when multiple messages arrive simultaneously.

**2. Chronological Processing**: Batches for the same conversation are processed in order. Batch #2 waits for Batch #1 to complete, ensuring message ordering.

**3. Timeout Protection** (10 minutes): If media download or transcription takes too long, messages are force-marked as ready with error flags. The batch processes with available data rather than waiting forever.

**4. Graceful Degradation**: Failed downloads or transcriptions don't block processing. Your handler receives the messages with error flags so you can decide how to respond.

### Example Scenarios

**Scenario 1: User sends 3 quick texts**
```
00:00 - "Hi"        → Batch created, process_after = 00:03
00:01 - "I need"    → Added to batch, process_after = 00:04
00:02 - "help"      → Added to batch, process_after = 00:05
00:05 - Window elapsed, all ready → Handler receives 3 messages
```

**Scenario 2: User sends text + audio (with transcription)**
```
00:00 - "Check this" (text)  → Batch created, message ready
00:01 - [2min audio]         → Added to batch, starts download
00:04 - Window elapsed BUT audio still processing → Waits
00:15 - Download complete    → Starts transcription
00:25 - Transcription done   → Message ready
00:25 - All ready            → Handler receives text + audio with transcription
```

**Scenario 3: User keeps sending messages (max window protection)**
```
00:00 - Msg 1 → Batch created, process_after = 00:03
00:02 - Msg 2 → process_after = 00:05
00:04 - Msg 3 → process_after = 00:07
...
00:28 - Msg 15 → process_after would be 00:31, BUT max_window (30s) caps it at 00:30
00:30 - Max window reached → Handler receives all 15 messages
```

**Scenario 4: Slow transcription with timeout**
```
00:00 - [Long audio]         → Batch created, starts download
03:00 - Download complete    → Starts transcription
10:00 - TIMEOUT (10 min)     → Message forced to ready with error
10:00 - Handler receives message with transcription_status = 'failed'
```

### Recommended Configurations

**For AI Chatbots** (collect context):
```php
'batch_window_seconds' => 5,       // Wait for user to finish typing
'batch_max_window_seconds' => 60,  // Allow longer conversations
'batch_max_messages' => 20,        // Higher limit for context
'transcription_enabled' => true,   // Understand voice messages
```

**For Quick Support Bots** (fast responses):
```php
'batch_window_seconds' => 2,       // Quick turnaround
'batch_max_window_seconds' => 15,  // Don't wait too long
'batch_max_messages' => 5,         // Process smaller batches
```

**For Immediate Processing** (no batching):
```php
'processing_mode' => 'immediate',  // Each message processed alone
```

## Message Type Filtering

Control which message types are accepted:

```php
// In config/whatsapp.php or per-phone in database
'allowed_message_types' => ['text', 'image', 'audio'], // Only these types
'allowed_message_types' => ['*'],  // All types (default)

// What to do with disallowed types
'on_disallowed_type' => 'ignore',     // Silently ignore
'on_disallowed_type' => 'auto_reply', // Send configured reply
'disallowed_type_reply' => 'Sorry, we only accept text messages.',
```

## Console Commands

```bash
# Install the package
php artisan whatsapp:install

# Sync message templates from Meta
php artisan whatsapp:sync-templates

# Process stale/stuck batches (runs automatically every 5 min)
php artisan whatsapp:process-stale-batches
```

## Queue Configuration

The package uses Laravel's queue system for async processing. Configure in `config/whatsapp.php`:

```php
'queue' => [
    'connection' => env('WHATSAPP_QUEUE_CONNECTION'), // null uses default
    'queue' => env('WHATSAPP_QUEUE_NAME', 'default'),
],
```

Make sure to run your queue worker:

```bash
php artisan queue:work --queue=whatsapp
```

## Testing

```bash
./vendor/bin/pest
```

## License

MIT License. See [LICENSE](LICENSE) for details.
