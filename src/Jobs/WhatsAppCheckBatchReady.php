<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
        // If batch has ready messages, process what we have
        if ($batch->hasMessages() && $batch->messages()->where('status', 'ready')->exists()) {
            Log::warning('Force processing stale batch with available messages', [
                'batch_id' => $batch->id,
                'age_minutes' => $batch->created_at->diffInMinutes(now()),
                'pending_count' => $batch->pendingMessagesCount(),
            ]);

            WhatsAppProcessBatch::dispatch($batch);

            return;
        }

        // No ready messages after 10 minutes - mark as failed
        Log::error('Batch failed: no ready messages after timeout', [
            'batch_id' => $batch->id,
            'age_minutes' => $batch->created_at->diffInMinutes(now()),
            'pending_count' => $batch->pendingMessagesCount(),
        ]);

        $batch->markAsFailed('Batch timeout: messages did not become ready within ' . self::MAX_BATCH_AGE_MINUTES . ' minutes');
    }
}
