<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;

class WhatsAppCheckBatchReady implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Maximum time (in minutes) a batch can stay in 'collecting' status.
     * After this, it will be force-processed or marked as failed.
     */
    protected const MAX_BATCH_AGE_MINUTES = 10;

    public function __construct(
        public WhatsAppMessageBatch $batch,
    ) {
        $this->onQueue(config('whatsapp.queue.queue'));
        $this->onConnection(config('whatsapp.queue.connection'));
    }

    public function handle(): void
    {
        $batch = $this->batch->fresh(['phone']);

        if (! $batch) {
            return;
        }

        // Skip if already processed or processing
        if ($batch->status !== WhatsAppMessageBatch::STATUS_COLLECTING) {
            return;
        }

        // Check if batch is too old - force process or fail it
        if ($this->isBatchTooOld($batch)) {
            $this->handleStaleBatch($batch);

            return;
        }

        // Check if should process
        if ($batch->shouldProcess()) {
            WhatsAppProcessBatch::dispatch($batch);

            return;
        }

        // If batch has pending messages (still downloading/transcribing),
        // schedule another check in a few seconds
        if ($batch->pendingMessagesCount() > 0) {
            self::dispatch($batch)
                ->delay(now()->addSeconds(5));
        }
        // Otherwise, if window hasn't elapsed yet, another check was
        // already scheduled by the latest incoming message
    }

    /**
     * Check if batch has been collecting for too long.
     */
    protected function isBatchTooOld(WhatsAppMessageBatch $batch): bool
    {
        return $batch->created_at->diffInMinutes(now()) >= self::MAX_BATCH_AGE_MINUTES;
    }

    /**
     * Handle a batch that has been stuck for too long.
     */
    protected function handleStaleBatch(WhatsAppMessageBatch $batch): void
    {
        $pendingCount = $batch->pendingMessagesCount();

        // Force any stuck PROCESSING messages to READY status
        // They've had enough time - mark them ready with failed status flags
        if ($pendingCount > 0) {
            $this->forceMessagesToReady($batch);
        }

        // Now check if we have any messages to process
        $readyCount = $batch->messages()->where('status', WhatsAppMessage::STATUS_READY)->count();

        if ($readyCount > 0) {
            Log::warning('Force processing stale batch with available messages', [
                'batch_id' => $batch->id,
                'age_minutes' => $batch->created_at->diffInMinutes(now()),
                'ready_count' => $readyCount,
                'forced_ready_count' => $pendingCount,
            ]);

            WhatsAppProcessBatch::dispatch($batch);

            return;
        }

        // No messages at all after 10 minutes - mark as failed
        Log::error('Batch failed: no messages after timeout', [
            'batch_id' => $batch->id,
            'age_minutes' => $batch->created_at->diffInMinutes(now()),
        ]);

        $batch->markAsFailed('Batch timeout: no messages became ready within ' . self::MAX_BATCH_AGE_MINUTES . ' minutes');

        // Trigger next batch so it doesn't get stuck waiting
        $this->triggerNextBatch($batch);
    }

    /**
     * Force all pending messages to ready status.
     * This is used when a batch times out but still has messages being processed.
     */
    protected function forceMessagesToReady(WhatsAppMessageBatch $batch): void
    {
        // Get all messages still in received/processing status
        $pendingMessages = $batch->messages()
            ->whereIn('status', [
                WhatsAppMessage::STATUS_RECEIVED,
                WhatsAppMessage::STATUS_PROCESSING,
            ])
            ->get();

        foreach ($pendingMessages as $message) {
            Log::warning('Force marking message as ready due to batch timeout', [
                'message_id' => $message->id,
                'batch_id' => $batch->id,
                'original_status' => $message->status,
                'media_status' => $message->media_status,
                'transcription_status' => $message->transcription_status,
            ]);

            // Mark media/transcription as failed if they were pending
            $updates = ['status' => WhatsAppMessage::STATUS_READY];

            if ($message->media_status === WhatsAppMessage::MEDIA_STATUS_PENDING ||
                $message->media_status === WhatsAppMessage::MEDIA_STATUS_DOWNLOADING) {
                $updates['media_status'] = WhatsAppMessage::MEDIA_STATUS_FAILED;
                $updates['error_message'] = 'Timeout: media download did not complete in time';
            }

            if ($message->transcription_status === WhatsAppMessage::TRANSCRIPTION_STATUS_PENDING ||
                $message->transcription_status === WhatsAppMessage::TRANSCRIPTION_STATUS_TRANSCRIBING) {
                $updates['transcription_status'] = WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED;
                $updates['error_message'] = 'Timeout: transcription did not complete in time';
            }

            $message->update($updates);
        }
    }

    /**
     * Trigger the next pending batch for the same conversation.
     */
    protected function triggerNextBatch(WhatsAppMessageBatch $completedBatch): void
    {
        $nextBatch = WhatsAppMessageBatch::where('whatsapp_conversation_id', $completedBatch->whatsapp_conversation_id)
            ->where('id', '>', $completedBatch->id)
            ->where('status', WhatsAppMessageBatch::STATUS_COLLECTING)
            ->orderBy('id')
            ->first();

        if ($nextBatch) {
            self::dispatch($nextBatch);
        }
    }
}
