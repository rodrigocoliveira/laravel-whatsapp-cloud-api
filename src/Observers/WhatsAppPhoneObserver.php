<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Observers;

use Illuminate\Support\Facades\Cache;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

class WhatsAppPhoneObserver
{
    /**
     * Handle the WhatsAppPhone "saved" event (covers both created and updated).
     */
    public function saved(WhatsAppPhone $phone): void
    {
        $this->clearCache($phone);
    }

    /**
     * Handle the WhatsAppPhone "deleted" event.
     */
    public function deleted(WhatsAppPhone $phone): void
    {
        $this->clearCache($phone);
    }

    /**
     * Clear the phone cache.
     */
    protected function clearCache(WhatsAppPhone $phone): void
    {
        Cache::forget("whatsapp:phone:{$phone->phone_id}");
    }
}
