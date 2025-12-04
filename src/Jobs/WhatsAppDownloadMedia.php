<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelWhatsAppCloud\Events\MediaDownloaded;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Services\MediaService;

class WhatsAppDownloadMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public WhatsAppMessage $message,
    ) {
        $this->onQueue(config('whatsapp.queue.queue'));
        $this->onConnection(config('whatsapp.queue.connection'));
    }

    public function handle(MediaService $mediaService): void
    {
        $message = $this->message->loadMissing(['phone', 'batch']);

        // Skip if media already downloaded
        if ($message->media_status === WhatsAppMessage::MEDIA_STATUS_DOWNLOADED) {
            return;
        }

        $message->update(['media_status' => WhatsAppMessage::MEDIA_STATUS_DOWNLOADING]);

        try {
            $path = $mediaService->download($message);

            $message->update([
                'media_status' => WhatsAppMessage::MEDIA_STATUS_DOWNLOADED,
                'local_media_path' => $path,
            ]);

            event(new MediaDownloaded($message));

            // If audio and transcription enabled, transcribe
            if ($message->isAudio() && $message->phone->transcription_enabled) {
                $message->update(['transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_PENDING]);
                WhatsAppTranscribeAudio::dispatch($message);
            } else {
                $message->markAsReady();
                $this->checkBatchProcessing($message);
            }

        } catch (Exception $e) {
            $message->update([
                'media_status' => WhatsAppMessage::MEDIA_STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            // Still mark as ready so batch can proceed
            $message->markAsReady();
            $this->checkBatchProcessing($message);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->message->loadMissing(['phone', 'batch']);

        $this->message->update([
            'media_status' => WhatsAppMessage::MEDIA_STATUS_FAILED,
            'error_message' => $exception?->getMessage(),
        ]);

        // Mark as ready anyway so batch processing can continue
        $this->message->markAsReady();
        $this->checkBatchProcessing($this->message);
    }

    /**
     * Check if batch should be processed after message becomes ready.
     */
    protected function checkBatchProcessing(WhatsAppMessage $message): void
    {
        $batch = $message->batch;

        if (! $batch || ! $batch->isCollecting()) {
            return;
        }

        // For immediate mode, dispatch directly
        if ($message->phone->isImmediateMode()) {
            WhatsAppProcessBatch::dispatch($batch);

            return;
        }

        // For batch mode, check if batch should process now
        if ($batch->shouldProcess()) {
            WhatsAppProcessBatch::dispatch($batch);
        }
    }
}
