<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Support\TemplateBuilder;

beforeEach(function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.template']]])]);

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);
});

it('sends named parameters and records them in Meta shape', function () {
    $message = (new TemplateBuilder($this->phone, new WhatsAppClient($this->phone), '5511999999999'))
        ->name('order_update')
        ->headerDocument('https://x.test/o.pdf', 'Orçamento.pdf')
        ->bodyParameters(['customer_name' => 'Marina'])
        ->addQuickReplyButton(0, 'talk_to_human')
        ->send();

    $header = ['type' => 'document', 'document' => ['link' => 'https://x.test/o.pdf', 'filename' => 'Orçamento.pdf']];

    Http::assertSent(fn (Request $request) => $request->data()['template']['components'][1]['parameters'][0]
        === ['type' => 'text', 'parameter_name' => 'customer_name', 'text' => 'Marina']);

    expect($message->template_parameters)->toBe([
        'header' => $header,
        'body' => [['type' => 'text', 'parameter_name' => 'customer_name', 'text' => 'Marina']],
        'buttons' => [['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 0, 'parameters' => [['type' => 'payload', 'payload' => 'talk_to_human']]]],
    ]);
});

it('appends body parameters one at a time', function () {
    $message = (new TemplateBuilder($this->phone, new WhatsAppClient($this->phone), '5511999999999'))
        ->name('order_update')
        ->addBodyParameter('Marina')
        ->addBodyParameter('#42')
        ->send();

    expect(array_column($message->template_parameters['body'], 'text'))->toBe(['Marina', '#42']);
});
