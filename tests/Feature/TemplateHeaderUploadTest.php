<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSendMessage;
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

it('stores a queued template header file and uploads it when the job runs', function () {
    Queue::fake();

    $message = ($this->builder)()
        ->headerDocument(UploadedFile::fake()->create('Orçamento.pdf', 4, 'application/pdf'))
        ->queue();

    Http::assertNothingSent();

    expect($message->media_id)->toBeNull()
        ->and($message->local_media_path)->toEndWith('.pdf')
        ->and($message->template_parameters['header'])->toBe(['type' => 'document', 'document' => ['filename' => 'Orçamento.pdf']]);

    (new WhatsAppSendMessage($message))->handle();

    $header = ['type' => 'document', 'document' => ['id' => 'media_123', 'filename' => 'Orçamento.pdf']];
    $message->refresh();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['template']['components'][0]['parameters'][0] === $header);

    expect($message->media_id)->toBe('media_123')
        ->and($message->content['components'][0]['parameters'][0])->toBe($header)
        ->and($message->template_parameters['header'])->toBe($header)
        ->and($message->delivery_status)->toBe(WhatsAppMessage::DELIVERY_STATUS_SENT);
});

it('does not upload again when a queued template is retried after the upload', function () {
    Queue::fake();

    $message = ($this->builder)()
        ->headerImage(UploadedFile::fake()->image('banner.jpg'))
        ->queue();

    (new WhatsAppSendMessage($message))->handle();

    // A retry of the same row (e.g. the send failed after the upload succeeded)
    $message->refresh()->update(['delivery_status' => WhatsAppMessage::DELIVERY_STATUS_QUEUED]);
    (new WhatsAppSendMessage($message))->handle();

    Http::assertSentCount(3); // one upload, two sends
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['template']['components'][0]['parameters'][0] === ['type' => 'image', 'image' => ['id' => 'media_123']]);
});

it('leaves a queued template with a link header untouched by the upload step', function () {
    Queue::fake();

    $message = ($this->builder)()->headerImage('https://x.test/a.jpg')->queue();

    (new WhatsAppSendMessage($message))->handle();

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/media'));
});
