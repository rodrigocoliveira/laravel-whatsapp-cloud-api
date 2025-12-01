<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;

class MessageFiltered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public WhatsAppMessage $message,
        public string $reason,
    ) {}
}
