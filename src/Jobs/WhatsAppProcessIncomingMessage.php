<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;

class WhatsAppProcessIncomingMessage implements ShouldQueue
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

    public function handle(): void
    {
        $message = $this->message;
        $phone = $message->phone;

        // Skip if message is not in received status
        if ($message->status !== WhatsAppMessage::STATUS_RECEIVED) {
            return;
        }

        // For immediate mode, just mark ready and process
        if ($phone->isImmediateMode()) {
            $this->handleImmediateMode($message);

            return;
        }

        // Find or create batch for this conversation
        $batch = $this->findOrCreateBatch($message);

        // Associate message with batch
        $message->update(['whatsapp_message_batch_id' => $batch->id]);

        // Update batch window
        $processAfter = Carbon::now()->addSeconds($phone->batch_window_seconds);
        $batch->update(['process_after' => $processAfter]);

        // Dispatch delayed job to check if batch is ready
        WhatsAppCheckBatchReady::dispatch($batch)
            ->delay($processAfter->addSecond());

        // Handle media download if needed
        if ($message->hasMedia() && $phone->auto_download_media) {
            $message->update([
                'status' => WhatsAppMessage::STATUS_PROCESSING,
                'media_status' => WhatsAppMessage::MEDIA_STATUS_PENDING,
            ]);
            WhatsAppDownloadMedia::dispatch($message);
        } else {
            $message->markAsReady();
        }
    }

    /**
     * Handle immediate processing mode.
     */
    protected function handleImmediateMode(WhatsAppMessage $message): void
    {
        $phone = $message->phone;

        // Create a single-message batch
        $batch = WhatsAppMessageBatch::create([
            'whatsapp_phone_id' => $phone->id,
            'whatsapp_conversation_id' => $message->whatsapp_conversation_id,
            'status' => WhatsAppMessageBatch::STATUS_COLLECTING,
            'first_message_at' => $message->created_at,
            'process_after' => Carbon::now(),
        ]);

        $message->update(['whatsapp_message_batch_id' => $batch->id]);

        // Handle media download if needed
        if ($message->hasMedia() && $phone->auto_download_media) {
            $message->update([
                'status' => WhatsAppMessage::STATUS_PROCESSING,
                'media_status' => WhatsAppMessage::MEDIA_STATUS_PENDING,
            ]);
            WhatsAppDownloadMedia::dispatch($message);
        } else {
            $message->markAsReady();
            // Immediately dispatch batch processing
            WhatsAppProcessBatch::dispatch($batch);
        }
    }

    /**
     * Find or create a batch for the message's conversation.
     */
    protected function findOrCreateBatch(WhatsAppMessage $message): WhatsAppMessageBatch
    {
        return DB::transaction(function () use ($message) {
            // Try to find an existing collecting batch
            $batch = WhatsAppMessageBatch::lockForUpdate()
                ->where('whatsapp_conversation_id', $message->whatsapp_conversation_id)
                ->where('status', WhatsAppMessageBatch::STATUS_COLLECTING)
                ->first();

            if ($batch) {
                return $batch;
            }

            // Create new batch
            return WhatsAppMessageBatch::create([
                'whatsapp_phone_id' => $message->whatsapp_phone_id,
                'whatsapp_conversation_id' => $message->whatsapp_conversation_id,
                'status' => WhatsAppMessageBatch::STATUS_COLLECTING,
                'first_message_at' => $message->created_at,
                'process_after' => Carbon::now()->addSeconds($message->phone->batch_window_seconds),
            ]);
        });
    }
}
