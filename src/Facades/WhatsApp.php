<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Facades;

use Illuminate\Support\Facades\Facade;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Support\MessageBuilder;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

/**
 * @method static WhatsAppManager phone(string $key)
 * @method static MessageBuilder to(string $phone)
 * @method static WhatsAppMessage sendText(string $to, string $message, bool $previewUrl = false)
 * @method static WhatsAppMessage sendImage(string $to, string $urlOrMediaId, ?string $caption = null)
 * @method static WhatsAppMessage sendVideo(string $to, string $urlOrMediaId, ?string $caption = null)
 * @method static WhatsAppMessage sendAudio(string $to, string $urlOrMediaId)
 * @method static WhatsAppMessage sendDocument(string $to, string $urlOrMediaId, ?string $filename = null, ?string $caption = null)
 * @method static WhatsAppMessage sendSticker(string $to, string $urlOrMediaId)
 * @method static WhatsAppMessage sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null)
 * @method static WhatsAppMessage sendContacts(string $to, array $contacts)
 * @method static WhatsAppMessage sendReaction(string $messageId, string $emoji)
 * @method static WhatsAppMessage removeReaction(string $messageId)
 * @method static array markAsRead(string $messageId)
 *
 * @see \Multek\LaravelWhatsAppCloud\WhatsAppManager
 */
class WhatsApp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WhatsAppManager::class;
    }
}
