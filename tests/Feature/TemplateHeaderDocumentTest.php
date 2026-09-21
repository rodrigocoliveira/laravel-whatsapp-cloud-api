<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Http::fake([
        '*' => Http::response(['messages' => [['id' => 'wamid.template']]], 200),
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

it('sends the filename with a template document header', function () {
    $url = 'https://example.com/pedido-corbi.pdf';

    $this->manager->phone('test')
        ->to('5511999999999')
        ->template('quote_request')
        ->headerDocument($url, 'pedido-corbi.pdf')
        ->send();

    Http::assertSent(function (Request $request) use ($url) {
        $header = $request->data()['template']['components'][0]['parameters'][0] ?? null;

        return $header === [
            'type' => 'document',
            'document' => ['link' => $url, 'filename' => 'pedido-corbi.pdf'],
        ];
    });
});

it('omits the filename key from a template document header when none is given', function () {
    $url = 'https://example.com/pedido-corbi.pdf';

    $this->manager->phone('test')
        ->to('5511999999999')
        ->template('quote_request')
        ->headerDocument($url)
        ->send();

    Http::assertSent(function (Request $request) use ($url) {
        $header = $request->data()['template']['components'][0]['parameters'][0] ?? null;

        return $header === [
            'type' => 'document',
            'document' => ['link' => $url],
        ];
    });
});

it('does not leak a document filename into an image template header', function () {
    $url = 'https://example.com/banner.jpg';

    $this->manager->phone('test')
        ->to('5511999999999')
        ->template('promo')
        ->headerImage($url)
        ->filename('should-not-appear.pdf')
        ->send();

    Http::assertSent(function (Request $request) use ($url) {
        $header = $request->data()['template']['components'][0]['parameters'][0] ?? null;

        return $header === [
            'type' => 'image',
            'image' => ['link' => $url],
        ];
    });
});

it('records the filename on the stored message content', function () {
    $url = 'https://example.com/pedido-corbi.pdf';

    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->template('quote_request')
        ->headerDocument($url, 'pedido-corbi.pdf')
        ->send();

    $stored = WhatsAppMessage::find($message->id);

    expect($stored->content['components'][0]['parameters'][0])->toBe([
        'type' => 'document',
        'document' => ['link' => $url, 'filename' => 'pedido-corbi.pdf'],
    ]);
});
