<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppFlow;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

class WhatsAppSyncFlows implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public WhatsAppPhone $phone,
    ) {
        $this->onQueue(config('whatsapp.queue.queue'));
        $this->onConnection(config('whatsapp.queue.connection'));
    }

    public function handle(): void
    {
        // Throws on any failed page, so nothing below runs on a partial list
        $flows = (new WhatsAppClient($this->phone))->getFlows();

        foreach ($flows as $flow) {
            // Only Meta's fields: handler, single_use and session_ttl_hours belong to the app
            WhatsAppFlow::updateOrCreate(
                ['whatsapp_phone_id' => $this->phone->id, 'flow_id' => $flow['id']],
                [
                    'name' => $flow['name'],
                    'status' => $flow['status'],
                    'categories' => $flow['categories'] ?? [],
                    'validation_errors' => $flow['validation_errors'] ?? [],
                    'last_synced_at' => now(),
                ]
            );
        }

        // Rows are kept, not deleted, so app settings and session history survive
        WhatsAppFlow::where('whatsapp_phone_id', $this->phone->id)
            ->whereNotIn('flow_id', array_column($flows, 'id'))
            ->update(['status' => WhatsAppFlow::STATUS_DELETED]);
    }
}
