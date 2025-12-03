<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Events\MessageFiltered;
use Multek\LaravelWhatsAppCloud\Events\MessageReceived;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppProcessIncomingMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Support\PhoneNumberHelper;

class WebhookProcessor
{
    /**
     * Process incoming webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function process(array $payload): void
    {
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $this->processEntry($entry);
        }
    }

    /**
     * Process a single webhook entry.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function processEntry(array $entry): void
    {
        $changes = $entry['changes'] ?? [];

        foreach ($changes as $change) {
            if (($change['field'] ?? '') !== 'messages') {
                continue;
            }

            $value = $change['value'] ?? [];
            $this->processChange($value);
        }
    }

    /**
     * Process a change value containing messages or statuses.
     *
     * @param  array<string, mixed>  $value
     */
    protected function processChange(array $value): void
    {
        $metadata = $value['metadata'] ?? [];
        $phoneNumberId = $metadata['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            Log::warning('WhatsApp webhook missing phone_number_id', ['value' => $value]);

            return;
        }

        // Find the phone
        $phone = WhatsAppPhone::where('phone_id', $phoneNumberId)
            ->where('is_active', true)
            ->first();

        if (! $phone) {
            Log::warning('WhatsApp webhook for unknown phone', ['phone_id' => $phoneNumberId]);

            return;
        }

        // Process messages
        $messages = $value['messages'] ?? [];
        $contacts = $value['contacts'] ?? [];

        foreach ($messages as $messageData) {
            $contactData = $contacts[0] ?? null;
            $this->processMessage($phone, $messageData, $contactData);
        }

        // Process statuses
        $statuses = $value['statuses'] ?? [];
        foreach ($statuses as $statusData) {
            $this->processStatus($phone, $statusData);
        }
    }

    /**
     * Process an incoming message.
     *
     * @param  array<string, mixed>  $messageData
     * @param  array<string, mixed>|null  $contactData
     */
    protected function processMessage(WhatsAppPhone $phone, array $messageData, ?array $contactData): void
    {
        $messageId = $messageData['id'] ?? null;
        $from = $messageData['from'] ?? null;
        $type = $messageData['type'] ?? 'unknown';
        $timestamp = $messageData['timestamp'] ?? null;

        if (! $messageId || ! $from) {
            Log::warning('WhatsApp webhook message missing id or from', ['data' => $messageData]);

            return;
        }

        // Normalize phone number to E.164 format for consistent storage
        $from = PhoneNumberHelper::normalize($from);

        // Check for duplicate
        if (WhatsAppMessage::where('message_id', $messageId)->exists()) {
            Log::debug('WhatsApp duplicate message ignored', ['message_id' => $messageId]);

            return;
        }

        // Get or create conversation
        $conversation = $this->getOrCreateConversation($phone, $from, $contactData);

        // Extract content based on type
        $content = $this->extractContent($type, $messageData);
        $textBody = $this->extractTextBody($type, $messageData);
        $mediaId = $this->extractMediaId($type, $messageData);
        $mimeType = $this->extractMimeType($type, $messageData);

        // Create the message record
        $message = WhatsAppMessage::create([
            'whatsapp_phone_id' => $phone->id,
            'whatsapp_conversation_id' => $conversation->id,
            'message_id' => $messageId,
            'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'type' => $type,
            'from' => $from,
            'to' => $phone->phone_number,
            'content' => $content,
            'text_body' => $textBody,
            'status' => WhatsAppMessage::STATUS_RECEIVED,
            'media_id' => $mediaId,
            'media_mime_type' => $mimeType,
            'created_at' => $timestamp ? Carbon::createFromTimestamp((int) $timestamp) : now(),
        ]);

        // Update conversation
        $conversation->update([
            'last_message_at' => $message->created_at,
            'contact_name' => $contactData['profile']['name'] ?? $conversation->contact_name,
        ]);
        $conversation->incrementUnread();

        // Fire message received event
        event(new MessageReceived($message));

        // Auto-start typing indicator if enabled
        if ($phone->auto_typing_enabled) {
            try {
                $client = new WhatsAppClient($phone);
                $client->startTyping($from);
            } catch (\Exception $e) {
                // Log but don't fail - typing is non-critical
                Log::warning('Failed to start typing indicator', [
                    'phone_id' => $phone->phone_id,
                    'to' => $from,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Check message type filtering
        if (! $phone->isMessageTypeAllowed($type)) {
            $this->handleFilteredMessage($message, $phone, $type);

            return;
        }

        // Dispatch job for further processing
        WhatsAppProcessIncomingMessage::dispatch($message);
    }

    /**
     * Process a status update.
     *
     * @param  array<string, mixed>  $statusData
     */
    protected function processStatus(WhatsAppPhone $phone, array $statusData): void
    {
        $messageId = $statusData['id'] ?? null;
        $status = $statusData['status'] ?? null;
        $timestamp = $statusData['timestamp'] ?? null;

        if (! $messageId || ! $status) {
            return;
        }

        $message = WhatsAppMessage::where('message_id', $messageId)->first();

        if (! $message) {
            Log::debug('WhatsApp status update for unknown message', ['message_id' => $messageId]);

            return;
        }

        $updates = ['delivery_status' => $status];
        $timestampCarbon = $timestamp ? Carbon::createFromTimestamp((int) $timestamp) : now();

        switch ($status) {
            case 'sent':
                $updates['sent_at'] = $timestampCarbon;
                break;
            case 'delivered':
                $updates['delivered_at'] = $timestampCarbon;
                break;
            case 'read':
                $updates['read_at'] = $timestampCarbon;
                break;
            case 'failed':
                $updates['error_message'] = $statusData['errors'][0]['message'] ?? 'Unknown error';
                break;
        }

        $message->update($updates);

        // Fire appropriate event
        match ($status) {
            'sent' => event(new \Multek\LaravelWhatsAppCloud\Events\MessageSent($message)),
            'delivered' => event(new \Multek\LaravelWhatsAppCloud\Events\MessageDelivered($message)),
            'read' => event(new \Multek\LaravelWhatsAppCloud\Events\MessageRead($message)),
            'failed' => event(new \Multek\LaravelWhatsAppCloud\Events\MessageFailed(
                $message,
                $statusData['errors'][0]['message'] ?? 'Unknown error'
            )),
            default => null,
        };
    }

    /**
     * Get or create a conversation.
     *
     * @param  array<string, mixed>|null  $contactData
     */
    protected function getOrCreateConversation(WhatsAppPhone $phone, string $contactPhone, ?array $contactData): WhatsAppConversation
    {
        return WhatsAppConversation::firstOrCreate(
            [
                'whatsapp_phone_id' => $phone->id,
                'contact_phone' => $contactPhone,
            ],
            [
                'contact_name' => $contactData['profile']['name'] ?? null,
                'last_message_at' => now(),
                'status' => WhatsAppConversation::STATUS_ACTIVE,
            ]
        );
    }

    /**
     * Handle a filtered message.
     */
    protected function handleFilteredMessage(WhatsAppMessage $message, WhatsAppPhone $phone, string $type): void
    {
        $message->markAsFiltered("Message type '{$type}' not allowed");

        event(new MessageFiltered($message, "Message type '{$type}' not allowed"));

        // Handle auto-reply if configured
        if ($phone->on_disallowed_type === 'auto_reply' && $phone->disallowed_type_reply) {
            // Queue an auto-reply message
            // This could be implemented via a job or directly
        }
    }

    /**
     * Extract content from message data.
     *
     * @param  array<string, mixed>  $messageData
     * @return array<string, mixed>
     */
    protected function extractContent(string $type, array $messageData): array
    {
        return match ($type) {
            'text' => $messageData['text'] ?? [],
            'image' => $messageData['image'] ?? [],
            'video' => $messageData['video'] ?? [],
            'audio' => $messageData['audio'] ?? [],
            'document' => $messageData['document'] ?? [],
            'sticker' => $messageData['sticker'] ?? [],
            'location' => $messageData['location'] ?? [],
            'contacts' => ['contacts' => $messageData['contacts'] ?? []],
            'interactive' => $messageData['interactive'] ?? [],
            'button' => $messageData['button'] ?? [],
            'reaction' => $messageData['reaction'] ?? [],
            'order' => $messageData['order'] ?? [],
            'system' => $messageData['system'] ?? [],
            default => $messageData,
        };
    }

    /**
     * Extract text body from message data.
     *
     * @param  array<string, mixed>  $messageData
     */
    protected function extractTextBody(string $type, array $messageData): ?string
    {
        return match ($type) {
            'text' => $messageData['text']['body'] ?? null,
            'image' => $messageData['image']['caption'] ?? null,
            'video' => $messageData['video']['caption'] ?? null,
            'document' => $messageData['document']['caption'] ?? null,
            'button' => $messageData['button']['text'] ?? null,
            default => null,
        };
    }

    /**
     * Extract media ID from message data.
     *
     * @param  array<string, mixed>  $messageData
     */
    protected function extractMediaId(string $type, array $messageData): ?string
    {
        return match ($type) {
            'image' => $messageData['image']['id'] ?? null,
            'video' => $messageData['video']['id'] ?? null,
            'audio' => $messageData['audio']['id'] ?? null,
            'document' => $messageData['document']['id'] ?? null,
            'sticker' => $messageData['sticker']['id'] ?? null,
            default => null,
        };
    }

    /**
     * Extract MIME type from message data.
     *
     * @param  array<string, mixed>  $messageData
     */
    protected function extractMimeType(string $type, array $messageData): ?string
    {
        return match ($type) {
            'image' => $messageData['image']['mime_type'] ?? null,
            'video' => $messageData['video']['mime_type'] ?? null,
            'audio' => $messageData['audio']['mime_type'] ?? null,
            'document' => $messageData['document']['mime_type'] ?? null,
            'sticker' => $messageData['sticker']['mime_type'] ?? null,
            default => null,
        };
    }
}
