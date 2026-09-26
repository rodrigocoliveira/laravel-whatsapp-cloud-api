<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;

/**
 * Fired once transcription retries are exhausted. The provider's reason is
 * stored on `$message->error_message`.
 */
class AudioTranscriptionFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public WhatsAppMessage $message,
    ) {}
}
