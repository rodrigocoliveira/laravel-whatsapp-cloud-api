<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Contracts;

use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;

interface MediaStorageInterface
{
    /**
     * Download media from WhatsApp and store it locally.
     *
     * @return string The local storage path
     */
    public function download(WhatsAppMessage $message): string;

    /**
     * Get the signed URL for a stored media file.
     */
    public function getUrl(WhatsAppMessage $message): ?string;

    /**
     * Delete a stored media file.
     */
    public function delete(WhatsAppMessage $message): bool;
}
