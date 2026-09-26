<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Multek\LaravelWhatsAppCloud\Events\AudioTranscribed;
use Multek\LaravelWhatsAppCloud\Events\AudioTranscriptionFailed;
use Multek\LaravelWhatsAppCloud\Exceptions\TranscriptionException;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppTranscribeAudio;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Services\Transcription\OpenAITranscriber;
use Multek\LaravelWhatsAppCloud\Services\TranscriptionService;
use OpenAI\Responses\Audio\TranscriptionResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Testing\ClientFake;

/**
 * openai-php/client returns a text/plain error body as the transcript text,
 * so the fake mirrors that by wrapping the raw body in a TranscriptionResponse.
 */
function fakeOpenAITranscriber(string $body): OpenAITranscriber
{
    $transcriber = new OpenAITranscriber;
    $transcriber->setClient(new ClientFake([
        TranscriptionResponse::from($body, MetaInformation::from([])),
    ]));

    return $transcriber;
}

function openAIErrorBody(): string
{
    return json_encode([
        'error' => [
            'message' => 'Incorrect API key provided: sk-proj-****abcd.',
            'type' => 'invalid_request_error',
            'param' => null,
            'code' => 'invalid_api_key',
        ],
    ], JSON_PRETTY_PRINT);
}

function makeAudioFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'wa_audio_');
    file_put_contents($path, 'fake-audio-bytes');

    return $path;
}

it('throws when OpenAI returns an error body as the transcript', function () {
    $path = makeAudioFile();

    expect(fn () => fakeOpenAITranscriber(openAIErrorBody())->transcribe($path))
        ->toThrow(TranscriptionException::class, 'Transcription failed: Incorrect API key provided: sk-proj-****abcd.');

    @unlink($path);
});

it('returns the transcript for a normal response', function () {
    $path = makeAudioFile();

    $result = fakeOpenAITranscriber('  Olá, tudo bem?  ')->transcribe($path);

    expect($result->text)->toBe('Olá, tudo bem?');

    @unlink($path);
});

it('does not treat JSON-looking speech without an error key as a failure', function () {
    $path = makeAudioFile();

    $result = fakeOpenAITranscriber('{"note": "said in braces"}')->transcribe($path);

    expect($result->text)->toBe('{"note": "said in braces"}');

    @unlink($path);
});

it('marks the message failed and fires AudioTranscriptionFailed when OpenAI rejects the request', function () {
    Event::fake([AudioTranscribed::class, AudioTranscriptionFailed::class]);
    Storage::fake('local');

    config([
        'whatsapp.transcription.default_service' => 'custom',
        'whatsapp.transcription.services.custom.class' => OpenAITranscriber::class,
    ]);
    app()->instance(OpenAITranscriber::class, fakeOpenAITranscriber(openAIErrorBody()));

    $phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
        'transcription_enabled' => true,
    ]);

    $message = WhatsAppMessage::create([
        'whatsapp_phone_id' => $phone->id,
        'message_id' => 'wamid.inbound.'.uniqid(),
        'direction' => WhatsAppMessage::DIRECTION_INBOUND,
        'type' => WhatsAppMessage::TYPE_AUDIO,
        'from' => '+5511999999999',
        'to' => '+15551234567',
        'content' => [],
        'status' => WhatsAppMessage::STATUS_RECEIVED,
        'local_media_disk' => 'local',
        'local_media_path' => 'whatsapp/media/2026/09/26/file.ogg',
        'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_PENDING,
    ]);
    Storage::disk('local')->put($message->local_media_path, 'fake-audio-bytes');

    $job = new WhatsAppTranscribeAudio($message);

    try {
        $job->handle(app(TranscriptionService::class));
        $this->fail('Expected the transcription job to throw.');
    } catch (TranscriptionException $e) {
        // Retries exhausted: the queue worker calls failed() with the last exception.
        $job->failed($e);
    }

    $message->refresh();

    expect($message->transcription_status)->toBe(WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED)
        ->and($message->transcription)->toBeNull()
        ->and($message->error_message)->toBe('Transcription failed: Incorrect API key provided: sk-proj-****abcd.');

    Event::assertNotDispatched(AudioTranscribed::class);
    Event::assertDispatched(AudioTranscriptionFailed::class, fn (AudioTranscriptionFailed $event) => $event->message->is($message));
});
