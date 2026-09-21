<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Multek\LaravelWhatsAppCloud\Exceptions\MessageSendException;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSendMessage;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppTranscribeAudio;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Storage::fake('local');

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->manager = app(WhatsAppManager::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeMeta(array $overrides = []): void
{
    Http::fake(array_merge([
        '*/test_phone_id/media' => Http::response(['id' => 'media_123']),
        '*/test_phone_id/messages' => Http::response(['messages' => [['id' => 'wamid.upload']]]),
    ], $overrides));
}

function tempPdf(string $contents = '%PDF-1.4 fake'): string
{
    $path = sys_get_temp_dir().'/whatsapp-upload-'.uniqid().'.pdf';
    file_put_contents($path, $contents);

    return $path;
}

it('uploads an UploadedFile to Meta, stores a local copy and sends by media id', function () {
    fakeMeta();
    $file = UploadedFile::fake()->create('voice.ogg', 12, 'audio/ogg');

    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->audio($file)
        ->send();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/media')
        && $request->hasFile('file', null, 'voice.ogg'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['audio'] === ['id' => 'media_123']);

    expect($message->media_id)->toBe('media_123')
        ->and($message->media_mime_type)->toBe('audio/ogg')
        ->and($message->media_size)->toBe(12 * 1024)
        ->and($message->media_status)->toBe(WhatsAppMessage::MEDIA_STATUS_DOWNLOADED)
        ->and($message->local_media_disk)->toBe('local')
        ->and($message->local_media_path)->toEndWith('.ogg')
        ->and($message->content['id'])->toBe('media_123')
        ->and($message->hasMedia())->toBeTrue();

    Storage::disk('local')->assertExists($message->local_media_path);
});

it('sends a local document with the caption and filename given', function () {
    fakeMeta();

    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->document(new File(tempPdf()))
        ->filename('pedido-corbi.pdf')
        ->caption('Segue o pedido')
        ->send();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['document'] === ['id' => 'media_123', 'filename' => 'pedido-corbi.pdf', 'caption' => 'Segue o pedido']);

    expect($message->media_mime_type)->toBe('application/pdf')
        ->and($message->local_media_path)->toEndWith('.pdf')
        ->and($message->content['filename'])->toBe('pedido-corbi.pdf')
        ->and($message->content['caption'])->toBe('Segue o pedido');
});

it('defaults the document filename to the original file name', function () {
    fakeMeta();

    $this->manager->phone('test')
        ->to('5511999999999')
        ->document(UploadedFile::fake()->create('contract.pdf', 4, 'application/pdf'))
        ->send();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['document'] === ['id' => 'media_123', 'filename' => 'contract.pdf']);
});

it('queues transcription for an uploaded audio when the phone has it enabled', function () {
    fakeMeta();
    Queue::fake();
    $this->phone->update(['transcription_enabled' => true]);

    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->audio(UploadedFile::fake()->create('voice.ogg', 12, 'audio/ogg'))
        ->send();

    Queue::assertPushed(WhatsAppTranscribeAudio::class, fn (WhatsAppTranscribeAudio $job) => $job->message->is($message));

    expect($message->fresh()->transcription_status)->toBe(WhatsAppMessage::TRANSCRIPTION_STATUS_PENDING)
        ->and($message->fresh()->status)->toBe(WhatsAppMessage::STATUS_PROCESSED);
});

it('stores the local copy when queued and uploads it from the disk when the job runs', function () {
    fakeMeta();
    Queue::fake();

    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->audio(UploadedFile::fake()->create('voice.ogg', 12, 'audio/ogg'))
        ->queue();

    Queue::assertPushed(WhatsAppSendMessage::class, fn (WhatsAppSendMessage $job) => $job->message->is($message));
    Http::assertNothingSent();

    expect($message->delivery_status)->toBe(WhatsAppMessage::DELIVERY_STATUS_QUEUED)
        ->and($message->media_id)->toBeNull()
        ->and($message->media_mime_type)->toBe('audio/ogg')
        ->and($message->media_size)->toBe(12 * 1024)
        ->and($message->local_media_disk)->toBe('local')
        ->and($message->local_media_path)->toEndWith('.ogg');

    Storage::disk('local')->assertExists($message->local_media_path);

    (new WhatsAppSendMessage($message))->handle();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/media')
        && $request->hasFile('file'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['audio'] === ['id' => 'media_123']);

    $sent = $message->fresh();

    expect($sent->media_id)->toBe('media_123')
        ->and($sent->content['id'])->toBe('media_123')
        ->and($sent->delivery_status)->toBe(WhatsAppMessage::DELIVERY_STATUS_SENT)
        ->and($sent->message_id)->toBe('wamid.upload');
});

it('stores nothing and records nothing when Meta rejects the upload', function () {
    fakeMeta([
        '*/test_phone_id/media' => Http::response(['error' => ['message' => 'Unsupported file type']], 400),
    ]);

    expect(fn () => $this->manager->phone('test')
        ->to('5511999999999')
        ->image(UploadedFile::fake()->image('photo.jpg'))
        ->send())->toThrow(MessageSendException::class, 'Unsupported file type');

    expect(WhatsAppMessage::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('still sends media given as a URL without uploading anything', function () {
    fakeMeta();

    $message = $this->manager->phone('test')
        ->to('5511999999999')
        ->image('https://example.com/photo.jpg')
        ->send();

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/media'));

    expect($message->media_id)->toBeNull()
        ->and($message->local_media_path)->toBeNull()
        ->and($message->getMediaUrl())->toBe('https://example.com/photo.jpg');
});
