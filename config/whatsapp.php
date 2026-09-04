<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API Settings
    |--------------------------------------------------------------------------
    */
    'api_version' => env('WHATSAPP_API_VERSION', 'v24.0'),
    'api_base_url' => 'https://graph.facebook.com',
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),

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
        'allowed_message_types' => ['*'],
        'on_disallowed_type' => 'ignore',
        'disallowed_type_reply' => 'This message type is not supported.',
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Flows
    |--------------------------------------------------------------------------
    | Endpoint-backed ("data_exchange") flows call back to your server between
    | screens. Static flows need none of this.
    */
    'flows' => [
        // Enables the data-exchange route. Disabled endpoints answer 404.
        'endpoint_enabled' => env('WHATSAPP_FLOW_ENDPOINT_ENABLED', false),

        // Appended to the webhook path, e.g. webhooks/whatsapp/flow
        'endpoint_path' => env('WHATSAPP_FLOW_ENDPOINT_PATH', 'flow'),

        // The business private key: an inline PEM or a path to a key file.
        'private_key' => env('WHATSAPP_FLOW_PRIVATE_KEY'),
        'private_key_passphrase' => env('WHATSAPP_FLOW_PRIVATE_KEY_PASSPHRASE'),

        // A class implementing FlowHandlerInterface.
        'handler' => env('WHATSAPP_FLOW_HANDLER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'path' => 'webhooks/whatsapp',
        'middleware' => ['api'],
        'log_retention_days' => env('WHATSAPP_WEBHOOK_LOG_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Storage
    |--------------------------------------------------------------------------
    */
    'media' => [
        'storage_disk' => env('WHATSAPP_MEDIA_DISK', 'local'),
        'storage_path' => 'whatsapp/media',
        'max_size' => 16 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transcription Services
    |--------------------------------------------------------------------------
    | Transcription converts audio messages to text. Language is auto-detected.
    |
    | Available services: 'openai', 'custom'
    |
    | For 'openai': requires openai-php/client package
    |   composer require openai-php/client
    |
    | For 'custom': provide your own TranscriptionServiceInterface implementation
    |   Set WHATSAPP_TRANSCRIPTION_CLASS to your class name
    */
    'transcription' => [
        'default_service' => env('WHATSAPP_TRANSCRIPTION_SERVICE', 'openai'),
        'services' => [
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('WHATSAPP_TRANSCRIPTION_MODEL', 'whisper-1'),
            ],
            'custom' => [
                'class' => env('WHATSAPP_TRANSCRIPTION_CLASS'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Message Pricing
    |--------------------------------------------------------------------------
    | Meta reports the billing category of each outbound message on status
    | webhooks (stored in `pricing_category`) but never the amount charged.
    | This rate card maps category to a per-message rate so the package can
    | estimate cost via `$message->estimatedCost()`.
    |
    | Keys under `rates` are E.164 dial-code prefixes (without "+"); the
    | longest prefix matching the recipient wins, and `default` is used when
    | nothing matches. Categories follow Meta's naming: marketing, utility,
    | authentication, authentication-international, service.
    |
    | Rates below are USD per message under Meta's per-message pricing (PMP).
    | Always verify against the current rate card before relying on them:
    | https://developers.facebook.com/docs/whatsapp/pricing
    */
    'pricing' => [
        'currency' => env('WHATSAPP_PRICING_CURRENCY', 'USD'),
        'rates' => [
            // Brazil
            '55' => [
                'marketing' => 0.0625,
                'utility' => 0.0080,
                'authentication' => 0.0315,
                'service' => 0.0,
            ],
            'default' => [
                'service' => 0.0,
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
        'queue' => env('WHATSAPP_QUEUE_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | Set 'channel' to use a specific logging channel (must be defined in
    | config/logging.php). Leave null to use Laravel's default logger.
    */
    'logging' => [
        'enabled' => true,
        'channel' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety Scheduler
    |--------------------------------------------------------------------------
    | Interval (in minutes) for checking stale/orphaned batches.
    */
    'stale_batch_check_interval' => 5,
];
