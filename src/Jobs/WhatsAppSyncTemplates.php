<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppTemplate;

class WhatsAppSyncTemplates implements ShouldQueue
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
        $phone = $this->phone;
        $client = new WhatsAppClient($phone);

        $templates = $client->getTemplates();

        foreach ($templates as $templateData) {
            $this->syncTemplate($phone, $templateData);
        }

        // Mark templates not in the response as potentially deleted/disabled
        $activeTemplateIds = collect($templates)->pluck('id')->toArray();

        WhatsAppTemplate::where('whatsapp_phone_id', $phone->id)
            ->whereNotIn('template_id', $activeTemplateIds)
            ->update(['status' => WhatsAppTemplate::STATUS_DISABLED]);
    }

    /**
     * Sync a single template.
     *
     * @param  array<string, mixed>  $templateData
     */
    protected function syncTemplate(WhatsAppPhone $phone, array $templateData): void
    {
        WhatsAppTemplate::updateOrCreate(
            [
                'whatsapp_phone_id' => $phone->id,
                'name' => $templateData['name'],
                'language' => $templateData['language'],
            ],
            [
                'template_id' => $templateData['id'],
                'category' => $templateData['category'],
                'status' => $templateData['status'],
                'components' => $templateData['components'] ?? [],
                'rejection_reason' => $templateData['rejected_reason'] ?? null,
                'last_synced_at' => now(),
            ]
        );
    }
}
