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
        $message = $this->message->loadMissing(['phone', 'conversation']);
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

        // Find or create batch and associate message atomically
        $batch = $this->findOrCreateBatchAndAssociateMessage($message);

        // Dispatch delayed job to check if batch is ready
        WhatsAppCheckBatchReady::dispatch($batch)
            ->delay($batch->process_after->copy()->addSecond());

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
     * Find or create a batch and associate the message atomically.
     *
     * This ensures batch creation, message association, and window update
     * all happen in a single transaction to prevent race conditions.
     */
    protected function findOrCreateBatchAndAssociateMessage(WhatsAppMessage $message): WhatsAppMessageBatch
    {
        return DB::transaction(function () use ($message) {
            $phone = $message->phone;

            // Try to find an existing collecting batch
            $batch = WhatsAppMessageBatch::lockForUpdate()
                ->where('whatsapp_conversation_id', $message->whatsapp_conversation_id)
                ->where('status', WhatsAppMessageBatch::STATUS_COLLECTING)
                ->first();

            $now = Carbon::now();

            if ($batch) {
                // Associate message with existing batch
                $message->update(['whatsapp_message_batch_id' => $batch->id]);

                // Calculate new process_after, but cap it to prevent infinite extension
                // Max window = first_message_at + batch_max_window_seconds (default 30s)
                $maxWindowSeconds = $phone->batch_max_window_seconds ?? 30;
                $maxProcessAfter = $batch->first_message_at->copy()->addSeconds($maxWindowSeconds);
                $newProcessAfter = $now->copy()->addSeconds($phone->batch_window_seconds);

                // Use the earlier of the two
                $processAfter = $newProcessAfter->lt($maxProcessAfter) ? $newProcessAfter : $maxProcessAfter;

                $batch->update(['process_after' => $processAfter]);

                return $batch->fresh();
            }

            // Create new batch with message associated
            $batch = WhatsAppMessageBatch::create([
                'whatsapp_phone_id' => $message->whatsapp_phone_id,
                'whatsapp_conversation_id' => $message->whatsapp_conversation_id,
                'status' => WhatsAppMessageBatch::STATUS_COLLECTING,
                'first_message_at' => $message->created_at ?? $now,
                'process_after' => $now->copy()->addSeconds($phone->batch_window_seconds),
            ]);

            // Associate message with new batch
            $message->update(['whatsapp_message_batch_id' => $batch->id]);

            return $batch;
        });
    }
}
