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
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
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
