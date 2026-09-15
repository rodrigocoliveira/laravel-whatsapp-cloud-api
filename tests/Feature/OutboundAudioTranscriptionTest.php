<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Multek\LaravelWhatsAppCloud\DTOs\TranscriptionResult;
use Multek\LaravelWhatsAppCloud\Events\AudioTranscribed;
use Multek\LaravelWhatsAppCloud\Events\MessageReady;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppTranscribeAudio;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Services\TranscriptionService;

function makeAudioMessage(WhatsAppPhone $phone, string $direction): WhatsAppMessage
{
    return WhatsAppMessage::create([
        'whatsapp_phone_id' => $phone->id,
        'message_id' => 'wamid.'.$direction.'.'.uniqid(),
        'direction' => $direction,
        'type' => WhatsAppMessage::TYPE_AUDIO,
        'from' => $direction === 'inbound' ? '+5511999999999' : '+15551234567',
        'to' => $direction === 'inbound' ? '+15551234567' : '+5511999999999',
        'content' => [],
        'status' => $direction === 'inbound' ? WhatsAppMessage::STATUS_RECEIVED : WhatsAppMessage::STATUS_PROCESSED,
    ]);
}

beforeEach(function () {
    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
        'transcription_enabled' => true,
    ]);
});

it('dispatches transcription automatically when an outbound audio message gets a local file', function () {
    Queue::fake();

    $message = makeAudioMessage($this->phone, WhatsAppMessage::DIRECTION_OUTBOUND);

    $message->update([
        'media_id' => 'media123',
        'local_media_disk' => 'local',
        'local_media_path' => 'whatsapp/media/2026/09/15/file.ogg',
    ]);

    Queue::assertPushed(WhatsAppTranscribeAudio::class, fn (WhatsAppTranscribeAudio $job) => $job->message->is($message));
    expect($message->fresh()->transcription_status)->toBe(WhatsAppMessage::TRANSCRIPTION_STATUS_PENDING);
});

it('does not dispatch transcription again when the message is saved again without changing the local path', function () {
    Queue::fake();

    $message = makeAudioMessage($this->phone, WhatsAppMessage::DIRECTION_OUTBOUND);

    $message->update([
        'local_media_disk' => 'local',
        'local_media_path' => 'whatsapp/media/2026/09/15/file.ogg',
    ]);

    Queue::assertPushed(WhatsAppTranscribeAudio::class, 1);

    $message->update(['error_message' => null]);

    Queue::assertPushed(WhatsAppTranscribeAudio::class, 1);
});

it('does not dispatch transcription when the phone has it disabled', function () {
    Queue::fake();

    $this->phone->update(['transcription_enabled' => false]);

    $message = makeAudioMessage($this->phone, WhatsAppMessage::DIRECTION_OUTBOUND);

    $message->update([
        'local_media_disk' => 'local',
        'local_media_path' => 'whatsapp/media/2026/09/15/file.ogg',
    ]);

    Queue::assertNotPushed(WhatsAppTranscribeAudio::class);
});

it('transcribes an outbound message without marking it ready or advancing the batch pipeline', function () {
    Event::fake([MessageReady::class, AudioTranscribed::class]);

    $message = makeAudioMessage($this->phone, WhatsAppMessage::DIRECTION_OUTBOUND);
    $message->update([
        'local_media_disk' => 'local',
        'local_media_path' => 'whatsapp/media/2026/09/15/file.ogg',
        'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_PENDING,
    ]);

    $this->mock(TranscriptionService::class, function ($mock) {
        $mock->shouldReceive('transcribe')->once()->andReturn(new TranscriptionResult(
            text: 'Hello from outbound audio',
            detectedLanguage: 'en',
            duration: 3.2,
        ));
    });

    Storage::fake('local');
    Storage::disk('local')->put($message->local_media_path, 'fake-audio-bytes');

    (new WhatsAppTranscribeAudio($message))->handle(app(TranscriptionService::class));

    $message->refresh();

    expect($message->status)->toBe(WhatsAppMessage::STATUS_PROCESSED)
        ->and($message->transcription)->toBe('Hello from outbound audio')
        ->and($message->transcription_status)->toBe(WhatsAppMessage::TRANSCRIPTION_STATUS_TRANSCRIBED);

    Event::assertNotDispatched(MessageReady::class);
    Event::assertDispatched(AudioTranscribed::class, fn (AudioTranscribed $event) => $event->message->is($message));
});

it('marks an inbound audio message ready after transcription as before', function () {
    Event::fake([MessageReady::class, AudioTranscribed::class]);

    $message = makeAudioMessage($this->phone, WhatsAppMessage::DIRECTION_INBOUND);
    $message->update([
        'local_media_disk' => 'local',
        'local_media_path' => 'whatsapp/media/2026/09/15/file.ogg',
        'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_PENDING,
    ]);

    $this->mock(TranscriptionService::class, function ($mock) {
        $mock->shouldReceive('transcribe')->once()->andReturn(new TranscriptionResult(
            text: 'Hello from inbound audio',
            detectedLanguage: 'en',
            duration: 3.2,
        ));
    });

    Storage::fake('local');
    Storage::disk('local')->put($message->local_media_path, 'fake-audio-bytes');

    (new WhatsAppTranscribeAudio($message))->handle(app(TranscriptionService::class));

    $message->refresh();

    expect($message->status)->toBe(WhatsAppMessage::STATUS_READY);

    Event::assertDispatched(MessageReady::class, fn (MessageReady $event) => $event->message->is($message));
    Event::assertDispatched(AudioTranscribed::class);
});

it('marks an outbound audio message failed without touching status when transcription fails permanently', function () {
    Event::fake([MessageReady::class]);

    $message = makeAudioMessage($this->phone, WhatsAppMessage::DIRECTION_OUTBOUND);
    $message->update([
        'local_media_disk' => 'local',
        'local_media_path' => 'whatsapp/media/2026/09/15/file.ogg',
        'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_PENDING,
    ]);

    (new WhatsAppTranscribeAudio($message))->failed(new Exception('boom'));

    $message->refresh();

    expect($message->status)->toBe(WhatsAppMessage::STATUS_PROCESSED)
        ->and($message->transcription_status)->toBe(WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED);

    Event::assertNotDispatched(MessageReady::class);
});

it('requestTranscription returns false for a non-audio message', function () {
    $message = WhatsAppMessage::create([
        'whatsapp_phone_id' => $this->phone->id,
        'message_id' => 'wamid.text.'.uniqid(),
        'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
        'type' => WhatsAppMessage::TYPE_TEXT,
        'from' => '+15551234567',
        'to' => '+5511999999999',
        'content' => [],
        'status' => WhatsAppMessage::STATUS_PROCESSED,
        'text_body' => 'hi',
    ]);

    expect($message->requestTranscription())->toBeFalse();
});

it('requestTranscription returns false when already transcribed', function () {
    $message = makeAudioMessage($this->phone, WhatsAppMessage::DIRECTION_OUTBOUND);
    $message->update([
        'local_media_disk' => 'local',
        'local_media_path' => 'whatsapp/media/2026/09/15/file.ogg',
        'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_TRANSCRIBED,
        'transcription' => 'already done',
    ]);

    expect($message->requestTranscription())->toBeFalse();
});
