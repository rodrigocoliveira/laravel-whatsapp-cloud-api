<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelWhatsAppCloud\DTOs\IncomingMessageContext;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;

class BatchProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public WhatsAppMessageBatch $batch,
        public IncomingMessageContext $context,
    ) {}
}
