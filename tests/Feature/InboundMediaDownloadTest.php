<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Multek\LaravelWhatsAppCloud\Exceptions\MediaDownloadException;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppDownloadMedia;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

beforeEach(function () {
    Storage::fake('local');
    config(['whatsapp.access_token' => 'app_wide_token']);

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
        'access_token' => 'phone_own_token',
    ]);

    $this->message = WhatsAppMessage::create([
        'whatsapp_phone_id' => $this->phone->id,
        'message_id' => 'wamid.inbound.'.uniqid(),
        'direction' => WhatsAppMessage::DIRECTION_INBOUND,
        'type' => WhatsAppMessage::TYPE_DOCUMENT,
        'from' => '+5511999999999',
        'to' => '+15551234567',
        'content' => [],
        'status' => WhatsAppMessage::STATUS_RECEIVED,
        'media_id' => '1372106838381257',
        'media_mime_type' => 'application/pdf',
        'media_status' => WhatsAppMessage::MEDIA_STATUS_PENDING,
    ]);
});

it('authenticates media requests with the token of the phone that received the message', function () {
    Http::fake([
        'graph.facebook.com/*/1372106838381257' => Http::response([
            'id' => '1372106838381257',
            'messaging_product' => 'whatsapp',
            'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1372106838381257',
            'mime_type' => 'application/pdf',
            'file_size' => 16938,
        ]),
        'lookaside.fbsbx.com/*' => Http::response('%PDF-1.4 fake'),
    ]);

    WhatsAppDownloadMedia::dispatchSync($this->message);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com')
        && $request->hasHeader('Authorization', 'Bearer phone_own_token'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'lookaside.fbsbx.com')
        && $request->hasHeader('Authorization', 'Bearer phone_own_token'));
    Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer app_wide_token'));

    $message = $this->message->fresh();

    expect($message->media_status)->toBe(WhatsAppMessage::MEDIA_STATUS_DOWNLOADED)
        ->and($message->local_media_disk)->toBe('local')
        ->and($message->local_media_path)->toEndWith('.pdf');

    Storage::disk('local')->assertExists($message->local_media_path);
});

it('reports the HTTP status and the Meta error when the media lookup is rejected', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid OAuth access token - Cannot parse access token',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ], 401),
    ]);

    expect(fn () => WhatsAppDownloadMedia::dispatchSync($this->message))
        ->toThrow(function (MediaDownloadException $e) {
            expect($e->getMessage())
                ->toContain('1372106838381257')
                ->toContain('401')
                ->toContain('Invalid OAuth access token')
                ->not->toContain('not found')
                ->and($e->getErrorData())->toMatchArray(['code' => 190]);
        });

    $message = $this->message->fresh();

    expect($message->media_status)->toBe(WhatsAppMessage::MEDIA_STATUS_FAILED)
        ->and($message->error_message)->toContain('Invalid OAuth access token');
});
