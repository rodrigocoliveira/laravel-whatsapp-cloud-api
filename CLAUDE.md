# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

Laravel package for integrating WhatsApp Cloud API. Handles webhook connections, media downloads, message sending, batch processing for AI agents, and multi-phone configurations.

## Development Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/pest

# Run a single test file
./vendor/bin/pest tests/Feature/WebhookHandlingTest.php

# Run a specific test by name
./vendor/bin/pest --filter "verifies webhook signature"

# Code style check
./vendor/bin/pint --test

# Code style fix
./vendor/bin/pint

# Static analysis
./vendor/bin/phpstan analyse
```

## Directory Structure

```
src/
├── Client/                   # WhatsApp API HTTP client
├── Console/Commands/         # Artisan commands (install, sync-templates)
├── Contracts/                # Interfaces for extensibility
├── DTOs/                     # Data transfer objects
│   └── MessageContent/       # Typed DTOs for each message type (14 types)
├── Events/                   # Observable Laravel events
├── Exceptions/               # Custom exception hierarchy
├── Facades/                  # WhatsApp facade
├── Http/                     # Controllers and middleware
├── Jobs/                     # Async job pipeline
├── Models/                   # Eloquent models
├── Services/                 # Business logic
│   └── Transcription/        # Audio transcription implementations
├── Support/                  # Message builders
├── WhatsAppManager.php       # Main manager class
└── WhatsAppServiceProvider.php

config/whatsapp.php           # Configuration (published to app)
database/migrations/          # Database schema
routes/webhooks.php           # Webhook route definitions
tests/                        # Pest test suite
```

## Architecture

### Core Flow

1. **Webhook Reception**: `WebhookController` receives POST from Meta, validates signature via `VerifyWhatsAppSignature` middleware
2. **Processing**: `WebhookProcessor` parses payload, creates `WhatsAppMessage` records, dispatches jobs
3. **Async Pipeline**: Jobs handle media download, transcription, batching
4. **Handler Execution**: Custom `MessageHandlerInterface` implementations process batches
5. **Response**: Handlers use `IncomingMessageContext` to reply via fluent API

### Key Classes

| Class | Purpose |
|-------|---------|
| `WhatsAppManager` | Main entry point, phone selection, message building |
| `WhatsAppClient` | HTTP client for Meta's Graph API |
| `WebhookProcessor` | Parses webhooks, orchestrates message processing |
| `MediaService` | Downloads and stores media files |
| `TranscriptionService` | Audio-to-text via OpenAI Whisper |
| `MessageBuilder` | Fluent API for constructing outbound messages |
| `IncomingMessageContext` | DTO passed to handlers with batch context |

### Database Models

| Model | Purpose |
|-------|---------|
| `WhatsAppPhone` | Phone configurations (key, handler, settings) |
| `WhatsAppConversation` | Tracks conversations per contact |
| `WhatsAppMessage` | All inbound/outbound messages |
| `WhatsAppMessageBatch` | Groups messages for batch processing |
| `WhatsAppTemplate` | Cached message templates from Meta |

### Job Pipeline

```
WhatsAppProcessIncomingMessage
  ├── WhatsAppDownloadMedia (if media present)
  │     └── WhatsAppTranscribeAudio (if audio + transcription enabled)
  └── WhatsAppCheckBatchReady (delayed safety check)
        └── WhatsAppProcessBatch (when batch ready)
```

### Events

Inbound: `MessageReceived`, `MessageFiltered`, `MessageReady`, `BatchReady`, `BatchProcessed`
Outbound: `MessageSent`, `MessageDelivered`, `MessageRead`, `MessageFailed`
Media: `MediaDownloaded`, `AudioTranscribed`

## Adding New Features

### Adding a New Message Type

1. Create DTO in `src/DTOs/MessageContent/` implementing the content structure
2. Add case to `WhatsAppMessage::getTypedContent()` to map type to DTO
3. Add extraction logic in `WebhookProcessor::extractMessageContent()`
4. If outbound, add method to `MessageBuilder`
5. Update `IncomingMessageContext` helpers if needed
6. Add tests

### Adding a New Event

1. Create event class in `src/Events/` with appropriate properties
2. Dispatch from relevant location (job, service, or controller)
3. Document in README events table

### Adding a New Console Command

1. Create command in `src/Console/Commands/`
2. Register in `WhatsAppServiceProvider::$commands`
3. Add to README console commands section

### Adding a New Transcription Service

1. Implement `TranscriptionServiceInterface` in `src/Services/Transcription/`
2. Add configuration in `config/whatsapp.php` under `transcription.services`
3. Register in `TranscriptionService::resolveService()`

### Extending the Message Handler

Handlers must implement `MessageHandlerInterface`:

```php
interface MessageHandlerInterface
{
    public function handle(IncomingMessageContext $context): void;
}
```

The `IncomingMessageContext` provides:
- `$context->messages` - Collection of `WhatsAppMessage`
- `$context->conversation` - The `WhatsAppConversation`
- `$context->phone` - The `WhatsAppPhone`
- `$context->getTextContent()` - Aggregated text from all messages
- `$context->getMedia()` - Messages with downloaded media
- `$context->getTranscriptions()` - Audio transcriptions
- `$context->reply($text)` - Quick text reply
- `$context->replyBuilder()` - Full `MessageBuilder` for complex replies

## Configuration Reference

Key configuration options in `config/whatsapp.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `api_version` | `v24.0` | Meta Graph API version |
| `processing_mode` | `batch` | `batch` or `immediate` |
| `batch_window_seconds` | `3` | Seconds to wait before processing |
| `batch_max_messages` | `10` | Max messages per batch |
| `auto_download_media` | `true` | Auto-download media files |
| `transcription_enabled` | `false` | Auto-transcribe audio |
| `webhook.verify_token` | env | Token for webhook verification |
| `webhook.app_secret` | env | Secret for signature verification |
| `media.storage_disk` | `local` | Laravel disk for media storage |
| `media.storage_path` | `whatsapp/media` | Path on disk |

## Testing Guidelines

- Use Pest framework with Laravel TestBench
- Mock external API calls (Meta Graph API, OpenAI)
- Test webhook signature verification with valid/invalid signatures
- Test job pipeline with fake queues
- Test message type filtering with various configurations

## Common Patterns

### Selecting a Phone

```php
// By key (defined in database)
WhatsApp::phone('support')->sendText($to, $text);

// By phone_id
WhatsApp::phoneById('123456789')->sendText($to, $text);
```

### Building Complex Messages

```php
WhatsApp::phone('support')
    ->to($recipient)
    ->interactive()
    ->header('image', 'https://example.com/img.jpg')
    ->body('Choose an option:')
    ->footer('Powered by WhatsApp')
    ->button('id_1', 'Option 1')
    ->button('id_2', 'Option 2')
    ->send();
```

### Handling Media in Handler

```php
public function handle(IncomingMessageContext $context): void
{
    foreach ($context->getMedia() as $message) {
        $path = $message->local_media_path;
        $disk = $message->local_media_disk;
        $url = Storage::disk($disk)->url($path);
        // Process media...
    }
}
```

## Debugging Tips

- Check `whatsapp_messages` table for message status and errors
- Check `whatsapp_message_batches` for stuck batches
- Verify webhook signature in logs if receiving 403 errors
- Ensure queue worker is running for async processing
- Check `media_status` column for media download issues
