<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Multek\LaravelWhatsAppCloud\Events\AudioTranscribed;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Services\TranscriptionService;

class WhatsAppTranscribeAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [30, 60];

    public function __construct(
        public WhatsAppMessage $message,
    ) {
        $this->onQueue(config('whatsapp.queue.queue', 'whatsapp'));
        $this->onConnection(config('whatsapp.queue.connection'));
    }

    public function handle(TranscriptionService $transcriptionService): void
    {
        $message = $this->message;

        // Skip if already transcribed
        if ($message->transcription_status === WhatsAppMessage::TRANSCRIPTION_STATUS_TRANSCRIBED) {
            return;
        }

        // Skip if no local file
        if (! $message->local_media_path || ! $message->local_media_disk) {
            $message->markAsReady();

            return;
        }

        $message->update(['transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_TRANSCRIBING]);

        try {
            $audioPath = Storage::disk($message->local_media_disk)->path($message->local_media_path);

            $result = $transcriptionService->transcribe($audioPath);

            $message->update([
                'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_TRANSCRIBED,
                'transcription' => $result->text,
                'transcription_language' => $result->detectedLanguage,
                'transcription_duration' => $result->duration,
            ]);

            $message->markAsReady();

            event(new AudioTranscribed($message));

        } catch (Exception $e) {
            $message->update([
                'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            // Still mark as ready - handler can work without transcription
            $message->markAsReady();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->message->update([
            'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED,
            'error_message' => $exception?->getMessage(),
        ]);

        // Mark as ready anyway so batch processing can continue
        $this->message->markAsReady();
    }
}
