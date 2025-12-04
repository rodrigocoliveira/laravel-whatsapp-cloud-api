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
        // Use atomic update to prevent double processing (race condition safe)
        $updated = DB::table('whatsapp_message_batches')
            ->where('id', $this->batch->id)
            ->where('status', WhatsAppMessageBatch::STATUS_COLLECTING)
            ->update(['status' => WhatsAppMessageBatch::STATUS_PROCESSING]);

        // If no rows updated, batch was already processing/completed
        if ($updated === 0) {
            return;
        }

        // Refresh the batch to get updated status
        $batch = $this->batch->fresh(['phone', 'conversation']);

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

        } catch (Exception $e) {
            $batch->markAsFailed($e->getMessage());

            report($e);

            throw $e;
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
    }
}
