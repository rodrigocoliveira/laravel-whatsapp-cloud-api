<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Multek\LaravelWhatsAppCloud\WhatsAppServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            WhatsAppServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup WhatsApp config
        $app['config']->set('whatsapp.access_token', 'test_token');
        $app['config']->set('whatsapp.webhook.verify_token', 'test_verify_token');
        $app['config']->set('whatsapp.webhook.app_secret', 'test_app_secret');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Generate a valid webhook signature for testing.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function generateSignature(array $payload): string
    {
        $secret = config('whatsapp.webhook.app_secret');
        $payloadString = json_encode($payload);
        $hash = hash_hmac('sha256', $payloadString, $secret);

        return 'sha256='.$hash;
    }

    /**
     * Get a sample text message webhook payload.
     *
     * @return array<string, mixed>
     */
    protected function getTextMessagePayload(string $from = '5511999999999', string $body = 'Hello'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WHATSAPP_BUSINESS_ACCOUNT_ID',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15551234567',
                                    'phone_number_id' => 'test_phone_id',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Test User'],
                                        'wa_id' => $from,
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => $from,
                                        'id' => 'wamid.'.uniqid(),
                                        'timestamp' => (string) time(),
                                        'type' => 'text',
                                        'text' => ['body' => $body],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get a sample image message webhook payload.
     *
     * @return array<string, mixed>
     */
    protected function getImageMessagePayload(string $from = '5511999999999'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WHATSAPP_BUSINESS_ACCOUNT_ID',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15551234567',
                                    'phone_number_id' => 'test_phone_id',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Test User'],
                                        'wa_id' => $from,
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => $from,
                                        'id' => 'wamid.'.uniqid(),
                                        'timestamp' => (string) time(),
                                        'type' => 'image',
                                        'image' => [
                                            'id' => 'media_id_'.uniqid(),
                                            'mime_type' => 'image/jpeg',
                                            'sha256' => 'abc123',
                                            'caption' => 'Test image',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
