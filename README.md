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
- Laravel 12.0+ (Laravel 11 reached end of security support in March 2026)

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

// Send a WhatsApp Flow (native in-conversation form)
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->flow('Complete your signup', flowId: '1234567890', cta: 'Sign up')
    ->flowToken('signup-42')            // optional, a UUID is generated otherwise
    ->flowScreen('WELCOME', ['name' => 'Rodrigo'])
    ->header('Signup')
    ->footer('Takes one minute')
    ->send();

// Send a location
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->location(-23.5505, -46.6333)
    ->name('Sao Paulo')
    ->address('Sao Paulo, Brazil')
    ->send();

// Send inside an existing conversation (addresses its contact and links the message to it)
WhatsApp::phone('support')
    ->conversation($conversation)
    ->text('Following up on your order')
    ->send();
```

Every outbound message is linked to a `WhatsAppConversation`, resolved from the sending phone and
the normalized recipient (created if none exists, so inbound and outbound land on the same thread),
and `send()` fires `MessageSent` once the API call succeeds. `queue()` links the pending message the
same way and fires `MessageSent` from the job after delivery.

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
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\FlowResponseContent;

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

    if ($content instanceof FlowResponseContent) {
        $submitted = $content->data;          // decoded response_json
        $email = $content->get('email');
        $flowToken = $content->flowToken;
    }
}
```

### Receiving WhatsApp Flow Responses

When the user submits a Flow, WhatsApp sends an `nfm_reply` interactive message. The
package decodes the `response_json` payload for you:

```php
public function handle(IncomingMessageContext $context): void
{
    foreach ($context->getFlowResponses() as $message) {
        $data = $message->getFlowData();   // ['flow_token' => ..., 'name' => ..., ...]

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);
    }

    // Or grab the decoded payload of every flow in the batch at once
    $payloads = $context->getFlowData();
}
```

The `flow_token` you set with `->flowToken()` is echoed back inside the response data,
so you can correlate a submission with whatever you were collecting.

### Endpoint-Backed (`data_exchange`) Flows

Flows whose screens call back to your server between steps need the encrypted
data-exchange endpoint.

**1. Generate a key pair and upload the public half to Meta:**

```bash
php artisan whatsapp:flow-key generate
```

Store the private key in `WHATSAPP_FLOW_PRIVATE_KEY` (an inline PEM or a path to a key
file), then:

```bash
php artisan whatsapp:flow-key upload --phone=support
```

**2. Enable the endpoint** with `WHATSAPP_FLOW_ENDPOINT_ENABLED=true`. It is served at
`webhooks/whatsapp/flow` and answers `404` while disabled. Register that URL as the
endpoint URI of your Flow in the WhatsApp Manager.

**3. Write a handler** implementing `FlowHandlerInterface` and point
`whatsapp.flows.handler` at it. Health checks (`ping`) and client error notifications are
answered by the package and never reach your handler.

```php
use Multek\LaravelWhatsAppCloud\Contracts\FlowHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowRequest;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowResponse;

class SignupFlowHandler implements FlowHandlerInterface
{
    public function handle(FlowRequest $request): FlowResponse
    {
        if ($request->screen === 'SIGNUP') {
            $user = User::create([
                'name' => $request->get('name'),
                'email' => $request->get('email'),
            ]);

            return FlowResponse::complete($request->flowToken, ['user_id' => $user->id]);
        }

        return FlowResponse::screen('SIGNUP', ['countries' => Country::pluck('name')]);
    }
}
```

Send the flow with `->flowDataExchange()` so the first screen calls your endpoint.

**How it is secured:** the endpoint has no signature check — authenticity comes from the
encryption, since only Meta can encrypt with the public key you registered. Any payload
that fails to decrypt is answered with `421`, which makes Meta refresh the public key. The
private key is only ever read from config; per-phone keys are not supported.

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

# Generate the RSA key pair for endpoint-backed Flows
php artisan whatsapp:flow-key generate

# Upload the Flow public key to Meta for a phone
php artisan whatsapp:flow-key upload --phone=support

# Smoke-test a flow against real traffic
php artisan whatsapp:flow-test send --phone=support --flow-id=123 --to=5511999999999
php artisan whatsapp:flow-test ping
```

`whatsapp:flow-test send` delivers a real flow message (in `draft` mode by default, so the
flow does not need publishing) and prints the wamid Meta returned, or the Graph API error
if it was rejected. `whatsapp:flow-test ping` encrypts a health check the way Meta does and
posts it to your own endpoint, proving the private key, the route and the response
encryption line up — a `421` there means the configured key does not match the one
uploaded to Meta.

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
