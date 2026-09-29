<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.template']]])]);

    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->template = fn (string $name = 'order_update') => app(WhatsAppManager::class)
        ->phone('test')->to('5511999999999')->template($name);
});

/**
 * @return array<int, array<string, mixed>>
 */
function sentComponents(): array
{
    $components = null;

    Http::assertSent(function (Request $request) use (&$components) {
        $components = $request->data()['template']['components'] ?? [];

        return true;
    });

    return $components;
}

it('keeps the payload of existing positional calls unchanged', function () {
    ($this->template)()
        ->headerText('#42')
        ->bodyParameters(['Marina', 'amanhã'])
        ->buttonParameters([1 => 'PED-1'])
        ->send();

    expect(sentComponents())->toBe([
        ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => '#42']]],
        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Marina'], ['type' => 'text', 'text' => 'amanhã']]],
        ['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => 'PED-1']]],
    ]);
});

it('keeps a plain buttonParameters list targeting button 0', function () {
    ($this->template)()->buttonParameters(['PED-1'])->send();

    expect(sentComponents()[0]['index'])->toBe(0);
});

it('sends named body and header parameters', function () {
    ($this->template)()
        ->headerText('#42', name: 'order_id')
        ->bodyParameters(['customer_name' => 'Marina'])
        ->send();

    expect(sentComponents())->toBe([
        ['type' => 'header', 'parameters' => [['type' => 'text', 'parameter_name' => 'order_id', 'text' => '#42']]],
        ['type' => 'body', 'parameters' => [['type' => 'text', 'parameter_name' => 'customer_name', 'text' => 'Marina']]],
    ]);
});

it('sends copy-code, url and quick-reply buttons at their explicit indexes', function () {
    ($this->template)()
        ->copyCodeButton(0, 'CORBI10')
        ->urlButton(1, 'PED-1')
        ->quickReplyButton(2, 'talk_to_human')
        ->send();

    expect(array_column(sentComponents(), 'sub_type'))->toBe(['copy_code', 'url', 'quick_reply']);
});

it('sends a digits-only header image as a media id', function () {
    $message = ($this->template)()->headerImage('1234567890')->send();

    expect(sentComponents()[0]['parameters'][0])->toBe(['type' => 'image', 'image' => ['id' => '1234567890']])
        ->and($message->media_id)->toBe('1234567890');
});

it('sends raw Meta components as given', function () {
    $raw = [['type' => 'body', 'parameters' => [['type' => 'date_time', 'date_time' => ['fallback_value' => 'amanhã']]]]];

    $message = ($this->template)()->components($raw)->send();

    expect(sentComponents())->toBe($raw)
        ->and($message->template_parameters['body'])->toBe($raw[0]['parameters']);
});

it('records template_parameters in the same shape it sent', function () {
    $message = ($this->template)()
        ->headerDocument('https://x.test/o.pdf', 'Orçamento.pdf')
        ->bodyParameters(['customer_name' => 'Marina'])
        ->copyCodeButton(0, 'CORBI10')
        ->send();

    $components = sentComponents();

    expect($message->fresh()->template_parameters)->toBe([
        'header' => $components[0]['parameters'][0],
        'body' => $components[1]['parameters'],
        'buttons' => [$components[2]],
    ])->and($message->fresh()->content['components'])->toBe($components);
});

it('rejects a header file on an interactive message', function () {
    app(WhatsAppManager::class)->phone('test')->to('5511999999999')
        ->buttons('Choose')
        ->headerImage(UploadedFile::fake()->image('a.jpg'))
        ->button('yes', 'Yes')
        ->send();
})->throws(InvalidArgumentException::class, 'only be sent with a template');
