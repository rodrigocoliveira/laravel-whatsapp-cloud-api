<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Storage::fake('local');

    Http::fake([
        '*/test_phone_id/media' => Http::response(['id' => 'media_123']),
        '*/test_phone_id/messages' => Http::response(['messages' => [['id' => 'wamid.template']]]),
    ]);

    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->builder = fn () => app(WhatsAppManager::class)->phone('test')->to('5511999999999')->template('quote_ready');
});

it('uploads a template document header, sends it by id and keeps a local copy', function () {
    $message = ($this->builder)()
        ->headerDocument(UploadedFile::fake()->create('Orçamento.pdf', 4, 'application/pdf'))
        ->bodyParameters(['Marina'])
        ->send();

    $header = ['type' => 'document', 'document' => ['id' => 'media_123', 'filename' => 'Orçamento.pdf']];

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/media')
        && $request->hasFile('file', null, 'Orçamento.pdf'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['template']['components'][0]['parameters'][0] === $header);

    expect($message->media_id)->toBe('media_123')
        ->and($message->media_mime_type)->toBe('application/pdf')
        ->and($message->media_status)->toBe(WhatsAppMessage::MEDIA_STATUS_DOWNLOADED)
        ->and($message->local_media_disk)->toBe('local')
        ->and($message->local_media_path)->toEndWith('.pdf')
        ->and($message->template_parameters['header'])->toBe($header)
        ->and($message->content['components'][0]['parameters'][0])->toBe($header);

    Storage::disk('local')->assertExists($message->local_media_path);
});

it('uses the given filename over the file name for a document header', function () {
    ($this->builder)()
        ->headerDocument(UploadedFile::fake()->create('tmp123.pdf', 4, 'application/pdf'), 'Orçamento.pdf')
        ->send();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['template']['components'][0]['parameters'][0]['document']['filename'] === 'Orçamento.pdf');
});

it('does not upload anything for a template with a link header', function () {
    ($this->builder)()->headerImage('https://x.test/a.jpg')->send();

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/media'));
});
