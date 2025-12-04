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
        $this->onQueue(config('whatsapp.queue.queue'));
        $this->onConnection(config('whatsapp.queue.connection'));
    }

    public function handle(TranscriptionService $transcriptionService): void
    {
        $message = $this->message->loadMissing(['phone', 'batch']);

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

        $tempPath = null;

        try {
            $audioPath = $this->getAudioPath($message, $tempPath);

            $result = $transcriptionService->transcribe($audioPath);

            $message->update([
                'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_TRANSCRIBED,
                'transcription' => $result->text,
                'transcription_language' => $result->detectedLanguage,
                'transcription_duration' => $result->duration,
            ]);

            $message->markAsReady();
            $this->triggerBatchProcessingIfImmediate($message);

            event(new AudioTranscribed($message));

        } catch (Exception $e) {
            $message->update([
                'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            // Still mark as ready - handler can work without transcription
            $message->markAsReady();
            $this->triggerBatchProcessingIfImmediate($message);
        } finally {
            // Clean up temp file if created
            if ($tempPath !== null && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Get the audio file path, downloading from cloud storage if needed.
     */
    protected function getAudioPath(WhatsAppMessage $message, ?string &$tempPath): string
    {
        $disk = Storage::disk($message->local_media_disk);

        // For cloud storage (S3, etc.), download to temp file first
        if (! $this->isLocalDisk()) {
            $extension = pathinfo($message->local_media_path, PATHINFO_EXTENSION);
            $tempPath = sys_get_temp_dir().'/whatsapp_audio_'.uniqid().'.'.$extension;

            file_put_contents($tempPath, $disk->get($message->local_media_path));

            return $tempPath;
        }

        // For local disk, use the path directly
        return $disk->path($message->local_media_path);
    }

    /**
     * Check if the storage disk is a local filesystem.
     */
    protected function isLocalDisk(): bool
    {
        $diskName = $this->message->local_media_disk;
        $driver = config("filesystems.disks.{$diskName}.driver");

        return $driver === 'local';
    }

    public function failed(?\Throwable $exception): void
    {
        $this->message->update([
            'transcription_status' => WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED,
            'error_message' => $exception?->getMessage(),
        ]);

        // Mark as ready anyway so batch processing can continue
        $this->message->markAsReady();
        $this->triggerBatchProcessingIfImmediate($this->message);
    }

    /**
     * Trigger batch processing for immediate mode.
     */
    protected function triggerBatchProcessingIfImmediate(WhatsAppMessage $message): void
    {
        $batch = $message->batch;

        if ($batch && $message->phone->isImmediateMode() && $batch->isCollecting()) {
            WhatsAppProcessBatch::dispatch($batch);
        }
    }
}
