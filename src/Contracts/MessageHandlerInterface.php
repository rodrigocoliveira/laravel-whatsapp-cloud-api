<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Contracts;

use Multek\LaravelWhatsAppCloud\DTOs\IncomingMessageContext;

interface MessageHandlerInterface
{
    /**
     * Handle a batch of incoming messages.
     *
     * Called after all messages in batch are ready:
     * - Media downloaded to local storage
     * - Audio transcribed (if enabled)
     */
    public function handle(IncomingMessageContext $context): void;
}
