<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Multek\LaravelWhatsAppCloud\Support\TemplateComponents;

it('builds positional body parameters from a list', function () {
    $components = (new TemplateComponents)->body(['Marina', 42])->toComponents();

    expect($components)->toBe([[
        'type' => 'body',
        'parameters' => [
            ['type' => 'text', 'text' => 'Marina'],
            ['type' => 'text', 'text' => '42'],
        ],
    ]]);
});

it('builds named body parameters from string keys', function () {
    $components = (new TemplateComponents)->body(['customer_name' => 'Marina', 'order_id' => '#42'])->toComponents();

    expect($components[0]['parameters'])->toBe([
        ['type' => 'text', 'parameter_name' => 'customer_name', 'text' => 'Marina'],
        ['type' => 'text', 'parameter_name' => 'order_id', 'text' => '#42'],
    ]);
});

it('rejects body parameters that mix named and positional keys', function () {
    (new TemplateComponents)->body(['customer_name' => 'Marina', 'Extra']);
})->throws(InvalidArgumentException::class, 'not a mix');

it('builds a header text parameter with and without a name', function () {
    expect((new TemplateComponents)->headerText('#42')->toRecord()['header'])
        ->toBe(['type' => 'text', 'text' => '#42'])
        ->and((new TemplateComponents)->headerText('#42', 'order_id')->toRecord()['header'])
        ->toBe(['type' => 'text', 'parameter_name' => 'order_id', 'text' => '#42']);
});

it('sends a digits-only header source as a media id and anything else as a link', function () {
    expect((new TemplateComponents)->headerMedia('image', '1234567890')->toRecord()['header'])
        ->toBe(['type' => 'image', 'image' => ['id' => '1234567890']])
        ->and((new TemplateComponents)->headerMedia('document', 'https://x.test/a.pdf', 'a.pdf')->toRecord()['header'])
        ->toBe(['type' => 'document', 'document' => ['link' => 'https://x.test/a.pdf', 'filename' => 'a.pdf']]);
});

it('rejects header media types Meta does not support', function () {
    (new TemplateComponents)->headerMedia('audio', 'https://x.test/a.ogg');
})->throws(InvalidArgumentException::class, 'image, video or document');

it('keeps a header file pending and resolves it to a media id after upload', function () {
    $file = UploadedFile::fake()->create('Orçamento.pdf', 4, 'application/pdf');
    $template = (new TemplateComponents)->headerMedia('document', $file);

    expect($template->headerFile())->toBe($file)
        ->and($template->headerMediaId())->toBeNull()
        ->and($template->toRecord()['header'])->toBe(['type' => 'document', 'document' => ['filename' => 'Orçamento.pdf']]);

    $template->resolveHeaderMedia('media_123');

    expect($template->headerMediaId())->toBe('media_123')
        ->and($template->toRecord()['header'])
        ->toBe(['type' => 'document', 'document' => ['id' => 'media_123', 'filename' => 'Orçamento.pdf']]);
});

it('drops a pending header file when the header is replaced by a url', function () {
    $template = (new TemplateComponents)
        ->headerMedia('image', UploadedFile::fake()->image('a.jpg'))
        ->headerMedia('image', 'https://x.test/b.jpg');

    expect($template->headerFile())->toBeNull()
        ->and($template->toRecord()['header'])->toBe(['type' => 'image', 'image' => ['link' => 'https://x.test/b.jpg']]);
});

it('builds each button type and sorts them by index', function () {
    $template = (new TemplateComponents)
        ->quickReplyButton(2, 'talk_to_human')
        ->urlButton(1, 'PED-1')
        ->copyCodeButton(0, 'CORBI10');

    expect($template->toComponents())->toBe([
        ['type' => 'button', 'sub_type' => 'copy_code', 'index' => 0, 'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'CORBI10']]],
        ['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => 'PED-1']]],
        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 2, 'parameters' => [['type' => 'payload', 'payload' => 'talk_to_human']]],
    ]);
});

it('rejects a second parameter for the same button index', function () {
    (new TemplateComponents)->urlButton(1, 'PED-1')->copyCodeButton(1, 'CORBI10');
})->throws(InvalidArgumentException::class, 'Template button 1');

it('rejects a raw button whose index is already set', function () {
    (new TemplateComponents)->urlButton(0, 'PED-1')->raw([
        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => 'x']]],
    ]);
})->throws(InvalidArgumentException::class, 'Template button 0');

it('round-trips raw Meta components and keeps unknown types after the known ones', function () {
    $raw = [
        ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => '#42']]],
        ['type' => 'body', 'parameters' => [['type' => 'currency', 'currency' => ['fallback_value' => 'R$10', 'code' => 'BRL', 'amount_1000' => 10000]]]],
        ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'PED-1']]],
        ['type' => 'limited_time_offer', 'parameters' => [['type' => 'limited_time_offer', 'limited_time_offer' => ['expiration_time_ms' => 1]]]],
    ];

    expect((new TemplateComponents)->raw($raw)->toComponents())->toBe($raw);
});

it('lets a later header or body write replace the earlier one', function () {
    $template = (new TemplateComponents)
        ->raw([['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'old']]]])
        ->body(['new'])
        ->headerText('first')
        ->headerText('second');

    expect($template->toRecord())->toBe([
        'header' => ['type' => 'text', 'text' => 'second'],
        'body' => [['type' => 'text', 'text' => 'new']],
        'buttons' => [],
    ]);
});

it('produces nothing when no parameters were given', function () {
    expect((new TemplateComponents)->toComponents())->toBe([])
        ->and((new TemplateComponents)->toRecord())->toBe(['header' => null, 'body' => [], 'buttons' => []]);
});

it('serializes a pending file header as a JSON object, not an array', function () {
    $header = (new TemplateComponents)->headerMedia('image', UploadedFile::fake()->image('a.jpg'))->toRecord()['header'];

    expect(json_encode($header))->toBe('{"type":"image","image":{}}');
});
