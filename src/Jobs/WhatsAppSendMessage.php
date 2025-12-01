<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Events\MessageFailed;
use Multek\LaravelWhatsAppCloud\Events\MessageSent;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;

class WhatsAppSendMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public WhatsAppMessage $message,
    ) {
        $this->onQueue(config('whatsapp.queue.queue', 'whatsapp'));
        $this->onConnection(config('whatsapp.queue.connection'));
    }

    public function handle(): void
    {
        $message = $this->message;

        // Skip if already sent
        if ($message->delivery_status !== WhatsAppMessage::DELIVERY_STATUS_QUEUED) {
            return;
        }

        $phone = $message->phone;
        $client = new WhatsAppClient($phone);

        try {
            $result = $this->sendMessage($client, $message);

            // Update message with the actual WhatsApp message ID
            $actualMessageId = $result['messages'][0]['id'] ?? null;
            if ($actualMessageId) {
                $message->update(['message_id' => $actualMessageId]);
            }

            $message->update([
                'status' => WhatsAppMessage::STATUS_PROCESSED,
                'delivery_status' => WhatsAppMessage::DELIVERY_STATUS_SENT,
                'sent_at' => now(),
            ]);

            event(new MessageSent($message));

        } catch (Exception $e) {
            $message->update([
                'status' => WhatsAppMessage::STATUS_FAILED,
                'delivery_status' => WhatsAppMessage::DELIVERY_STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            event(new MessageFailed($message, $e->getMessage()));

            throw $e;
        }
    }

    /**
     * Send the message based on its type.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    protected function sendMessage(WhatsAppClient $client, WhatsAppMessage $message): array
    {
        $content = $message->content ?? [];
        $to = $message->to;

        return match ($message->type) {
            'text' => $client->sendText($to, $content['body'] ?? '', $content['preview_url'] ?? false),
            'image' => $client->sendImage($to, $content['url'] ?? $content['id'] ?? '', $content['caption'] ?? null),
            'video' => $client->sendVideo($to, $content['url'] ?? $content['id'] ?? '', $content['caption'] ?? null),
            'audio' => $client->sendAudio($to, $content['url'] ?? $content['id'] ?? ''),
            'document' => $client->sendDocument(
                $to,
                $content['url'] ?? $content['id'] ?? '',
                $content['filename'] ?? null,
                $content['caption'] ?? null
            ),
            'sticker' => $client->sendSticker($to, $content['url'] ?? $content['id'] ?? ''),
            'location' => $client->sendLocation(
                $to,
                $content['latitude'] ?? 0,
                $content['longitude'] ?? 0,
                $content['name'] ?? null,
                $content['address'] ?? null
            ),
            'contacts' => $client->sendContacts($to, $content['contacts'] ?? []),
            'reaction' => $client->sendReaction($content['message_id'] ?? '', $content['emoji'] ?? ''),
            'template' => $client->sendTemplate(
                $to,
                $message->template_name ?? '',
                $content['components'] ?? [],
                $content['language'] ?? 'pt_BR'
            ),
            'interactive' => $this->sendInteractive($client, $message),
            default => throw new Exception("Unsupported message type: {$message->type}"),
        };
    }

    /**
     * Send interactive message based on subtype.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    protected function sendInteractive(WhatsAppClient $client, WhatsAppMessage $message): array
    {
        $content = $message->content ?? [];
        $to = $message->to;
        $type = $content['type'] ?? 'button';

        return match ($type) {
            'button' => $client->sendButtons(
                $to,
                $content['body'] ?? '',
                $content['buttons'] ?? [],
                $content['header'] ?? null,
                $content['footer'] ?? null
            ),
            'list' => $client->sendList(
                $to,
                $content['body'] ?? '',
                $content['button_text'] ?? '',
                $content['sections'] ?? [],
                $content['header'] ?? null,
                $content['footer'] ?? null
            ),
            'cta_url' => $client->sendCtaUrl(
                $to,
                $content['body'] ?? '',
                $content['button_text'] ?? '',
                $content['url'] ?? '',
                $content['header'] ?? null,
                $content['footer'] ?? null
            ),
            default => throw new Exception("Unsupported interactive type: {$type}"),
        };
    }

    public function failed(?\Throwable $exception): void
    {
        $this->message->update([
            'status' => WhatsAppMessage::STATUS_FAILED,
            'delivery_status' => WhatsAppMessage::DELIVERY_STATUS_FAILED,
            'error_message' => $exception?->getMessage(),
        ]);

        event(new MessageFailed($this->message, $exception?->getMessage() ?? 'Unknown error'));
    }
}
