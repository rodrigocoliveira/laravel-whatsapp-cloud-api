<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Support;

use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

/**
 * Resolve the conversation an outbound message belongs to.
 *
 * Mirrors WebhookProcessor::getOrCreateConversation() so that inbound and
 * outbound messages for the same contact land on the same conversation row.
 */
class OutboundConversationResolver
{
    public static function resolve(WhatsAppPhone $phone, string $to, ?WhatsAppConversation $conversation = null): WhatsAppConversation
    {
        $conversation ??= WhatsAppConversation::firstOrCreate(
            [
                'whatsapp_phone_id' => $phone->id,
                'contact_phone' => PhoneNumberHelper::normalize($to),
            ],
            [
                'last_message_at' => now(),
                'status' => WhatsAppConversation::STATUS_ACTIVE,
            ]
        );

        $conversation->update(['last_message_at' => now()]);

        return $conversation;
    }
}
