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
