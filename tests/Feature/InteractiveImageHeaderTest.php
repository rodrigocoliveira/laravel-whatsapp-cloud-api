<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Http::fake([
        '*' => Http::response(['messages' => [['id' => 'wamid.interactive']]], 200),
    ]);

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);
});

it('sends image headers on interactive messages', function (string $method, array $arguments) {
    $client = new WhatsAppClient($this->phone);

    $client->{$method}(...$arguments);

    Http::assertSent(function ($request) {
        expect($request->data()['interactive']['header'])->toBe([
            'type' => 'image',
            'image' => ['link' => 'https://example.com/welcome.jpg'],
        ]);

        return true;
    });
})->with([
    'buttons' => [
        'sendButtons',
        ['5511999999999', 'Welcome', [['id' => 'start', 'title' => 'Start']], ['type' => 'image', 'image' => 'https://example.com/welcome.jpg']],
    ],
    'list' => [
        'sendList',
        ['5511999999999', 'Welcome', 'Choose', [['title' => 'Options', 'rows' => [['id' => 'start', 'title' => 'Start']]]], ['type' => 'image', 'image' => 'https://example.com/welcome.jpg']],
    ],
    'cta url' => [
        'sendCtaUrl',
        ['5511999999999', 'Welcome', 'Open', 'https://example.com', ['type' => 'image', 'image' => 'https://example.com/welcome.jpg']],
    ],
]);

it('uses a media id for a non-url interactive image header', function () {
    (new WhatsAppClient($this->phone))->sendButtons(
        '5511999999999',
        'Welcome',
        [['id' => 'start', 'title' => 'Start']],
        ['type' => 'image', 'image' => '123456789']
    );

    Http::assertSent(function ($request) {
        expect($request->data()['interactive']['header'])->toBe([
            'type' => 'image',
            'image' => ['id' => '123456789'],
        ]);

        return true;
    });
});

it('builds and stores an interactive image header through the fluent builder', function () {
    $message = app(WhatsAppManager::class)
        ->phone('test')
        ->to('5511999999999')
        ->buttons('Welcome')
        ->headerImage('https://example.com/welcome.jpg')
        ->button('start', 'Start')
        ->send();

    Http::assertSent(function ($request) {
        expect($request->data()['interactive']['header'])->toBe([
            'type' => 'image',
            'image' => ['link' => 'https://example.com/welcome.jpg'],
        ]);

        return true;
    });

    expect($message->content['header'])->toBe([
        'type' => 'image',
        'image' => 'https://example.com/welcome.jpg',
    ]);
});

it('sends a queued interactive image header from the stored record', function () {
    config()->set('queue.default', 'sync');

    $message = app(WhatsAppManager::class)
        ->phone('test')
        ->to('5511999999999')
        ->buttons('Welcome')
        ->headerImage('https://example.com/welcome.jpg')
        ->button('start', 'Start')
        ->queue();

    Http::assertSent(function ($request) {
        expect($request->data()['interactive']['header'])->toBe([
            'type' => 'image',
            'image' => ['link' => 'https://example.com/welcome.jpg'],
        ]);

        return true;
    });

    expect($message->fresh()->message_id)->toBe('wamid.interactive');
});

it('keeps the existing template image header behavior', function () {
    app(WhatsAppManager::class)
        ->phone('test')
        ->to('5511999999999')
        ->template('welcome')
        ->headerImage('https://example.com/welcome.jpg')
        ->send();

    Http::assertSent(function ($request) {
        expect($request->data()['template']['components'][0])->toBe([
            'type' => 'header',
            'parameters' => [[
                'type' => 'image',
                'image' => ['link' => 'https://example.com/welcome.jpg'],
            ]],
        ]);

        return true;
    });
});

it('keeps text interactive headers backward compatible', function () {
    (new WhatsAppClient($this->phone))->sendButtons(
        '5511999999999',
        'Welcome',
        [['id' => 'start', 'title' => 'Start']],
        'Greeting'
    );

    Http::assertSent(function ($request) {
        expect($request->data()['interactive']['header'])->toBe([
            'type' => 'text',
            'text' => 'Greeting',
        ]);

        return true;
    });
});
