<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppFlowSession;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;

/**
 * A flow sent by the package was submitted: its `nfm_reply` arrived.
 */
class FlowCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public WhatsAppFlowSession $session,
        public WhatsAppMessage $message,
    ) {}
}
