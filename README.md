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
        $context->replyBuilder()
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
