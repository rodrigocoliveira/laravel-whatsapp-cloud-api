<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;

class WhatsAppCheckStaleBatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(config('whatsapp.queue.queue', 'whatsapp'));
        $this->onConnection(config('whatsapp.queue.connection'));
    }

    public function handle(): void
    {
        // Find batches that should have been processed (30 seconds grace period)
        $staleBatches = WhatsAppMessageBatch::query()
            ->where('status', WhatsAppMessageBatch::STATUS_COLLECTING)
            ->where('process_after', '<', Carbon::now()->subSeconds(30))
            ->get();

        foreach ($staleBatches as $batch) {
            if ($batch->shouldProcess()) {
                WhatsAppProcessBatch::dispatch($batch);
            }
        }
    }
}
