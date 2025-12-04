<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Multek\LaravelWhatsAppCloud\Contracts\MessageHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\IncomingMessageContext;
use Multek\LaravelWhatsAppCloud\Events\BatchProcessed;
use Multek\LaravelWhatsAppCloud\Events\BatchReady;
use Multek\LaravelWhatsAppCloud\Exceptions\HandlerException;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;

class WhatsAppProcessBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public WhatsAppMessageBatch $batch,
    ) {
        $this->onQueue(config('whatsapp.queue.queue'));
        $this->onConnection(config('whatsapp.queue.connection'));
    }

    public function handle(): void
    {
        // Use a transaction with locking to ensure atomic check-and-update
        // This prevents race conditions in chronological order verification
        $batch = DB::transaction(function () {
            // Lock this batch and all batches for the same conversation
            $batch = WhatsAppMessageBatch::lockForUpdate()
                ->with(['phone', 'conversation'])
                ->find($this->batch->id);

            if (! $batch) {
                return null;
            }

            // Skip if not in collecting status
            if ($batch->status !== WhatsAppMessageBatch::STATUS_COLLECTING) {
                return null;
            }

            // Check if there's an older batch still pending for this conversation
            // This check is now atomic with the status update
            $olderPendingBatch = WhatsAppMessageBatch::where('whatsapp_conversation_id', $batch->whatsapp_conversation_id)
                ->where('id', '<', $batch->id)
                ->whereIn('status', [
                    WhatsAppMessageBatch::STATUS_COLLECTING,
                    WhatsAppMessageBatch::STATUS_PROCESSING,
                ])
                ->lockForUpdate()
                ->exists();

            if ($olderPendingBatch) {
                // Return a marker to indicate we need to reschedule
                return 'reschedule';
            }

            // Atomically update status to processing
            $batch->update(['status' => WhatsAppMessageBatch::STATUS_PROCESSING]);

            return $batch->fresh(['phone', 'conversation']);
        });

        // Handle reschedule case (outside transaction)
        if ($batch === 'reschedule') {
            WhatsAppCheckBatchReady::dispatch($this->batch)
                ->delay(now()->addSeconds(10));

            return;
        }

        if (! $batch) {
            return;
        }

        try {
            $phone = $batch->phone;
            $conversation = $batch->conversation;
            /** @var \Illuminate\Database\Eloquent\Collection<int, WhatsAppMessage> $messages */
            $messages = $batch->messages()
                ->where('status', WhatsAppMessage::STATUS_READY)
                ->orderBy('created_at')
                ->with(['phone', 'conversation', 'batch'])
                ->get();

            // Build context
            $context = new IncomingMessageContext(
                phone: $phone,
                conversation: $conversation,
                batch: $batch,
                messages: $messages,
            );

            // Fire batch ready event
            event(new BatchReady($batch));

            // Instantiate and call handler
            if ($phone->handler) {
                $handler = $this->resolveHandler($phone->handler);
                $handler->handle($context);
            }

            // Mark all messages as processed
            foreach ($messages as $message) {
                $message->markAsProcessed();
            }

            $batch->markAsCompleted();

            event(new BatchProcessed($batch, $context));

            // Trigger next batch in queue for this conversation (if any)
            $this->triggerNextBatch($batch);

        } catch (Exception $e) {
            $batch->markAsFailed($e->getMessage());

            // Still trigger next batch even on failure
            $this->triggerNextBatch($this->batch);

            report($e);

            throw $e;
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
            WhatsAppCheckBatchReady::dispatch($nextBatch);
        }
    }

    /**
     * Resolve the handler class.
     *
     * @throws HandlerException
     */
    protected function resolveHandler(string $handlerClass): MessageHandlerInterface
    {
        if (! class_exists($handlerClass)) {
            throw HandlerException::handlerNotFound($handlerClass);
        }

        $handler = app($handlerClass);

        if (! $handler instanceof MessageHandlerInterface) {
            throw HandlerException::invalidHandler($handlerClass);
        }

        return $handler;
    }

    public function failed(?\Throwable $exception): void
    {
        $this->batch->markAsFailed($exception?->getMessage() ?? 'Unknown error');

        // Trigger next batch even on failure so it doesn't get stuck
        $this->triggerNextBatch($this->batch);
    }
}
