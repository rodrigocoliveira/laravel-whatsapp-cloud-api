<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Http::fake([
        '*' => Http::response(['messages' => [['id' => 'wamid.media']]], 200),
    ]);

    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->manager = app(WhatsAppManager::class);
});

it('reports media presence and URL for outbound sticker sent by URL', function () {
    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->sticker('https://example.com/happy.webp')
        ->send();

    expect($message->hasMedia())->toBeTrue()
        ->and($message->getMediaUrl())->toBe('https://example.com/happy.webp');
});

it('reports media presence and URL for outbound image sent by URL', function () {
    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->image('https://example.com/photo.jpg')
        ->caption('Look')
        ->send();

    expect($message->hasMedia())->toBeTrue()
        ->and($message->getMediaUrl())->toBe('https://example.com/photo.jpg');
});
