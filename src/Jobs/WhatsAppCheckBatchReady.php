<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;

class WhatsAppCheckBatchReady implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

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
}
