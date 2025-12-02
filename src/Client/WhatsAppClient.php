<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Client;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Multek\LaravelWhatsAppCloud\Exceptions\MediaDownloadException;
use Multek\LaravelWhatsAppCloud\Exceptions\MessageSendException;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

class WhatsAppClient implements WhatsAppClientInterface
{
    protected string $baseUrl;

    protected string $apiVersion;

    public function __construct(
        protected WhatsAppPhone $phone,
    ) {
        $this->baseUrl = config('whatsapp.api_base_url', 'https://graph.facebook.com');
        $this->apiVersion = config('whatsapp.api_version', 'v24.0');
    }

    /**
     * Send a text message.
     */
    public function sendText(string $to, string $message, bool $previewUrl = false): array
    {
        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'text',
            'text' => [
                'preview_url' => $previewUrl,
                'body' => $message,
            ],
        ]);
    }

    /**
     * Send an image message.
     */
    public function sendImage(string $to, string $urlOrMediaId, ?string $caption = null): array
    {
        $imagePayload = $this->isUrl($urlOrMediaId)
            ? ['link' => $urlOrMediaId]
            : ['id' => $urlOrMediaId];

        if ($caption) {
            $imagePayload['caption'] = $caption;
        }

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'image',
            'image' => $imagePayload,
        ]);
    }

    /**
     * Send a video message.
     */
    public function sendVideo(string $to, string $urlOrMediaId, ?string $caption = null): array
    {
        $videoPayload = $this->isUrl($urlOrMediaId)
            ? ['link' => $urlOrMediaId]
            : ['id' => $urlOrMediaId];

        if ($caption) {
            $videoPayload['caption'] = $caption;
        }

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'video',
            'video' => $videoPayload,
        ]);
    }

    /**
     * Send an audio message.
     */
    public function sendAudio(string $to, string $urlOrMediaId): array
    {
        $audioPayload = $this->isUrl($urlOrMediaId)
            ? ['link' => $urlOrMediaId]
            : ['id' => $urlOrMediaId];

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'audio',
            'audio' => $audioPayload,
        ]);
    }

    /**
     * Send a document message.
     */
    public function sendDocument(string $to, string $urlOrMediaId, ?string $filename = null, ?string $caption = null): array
    {
        $documentPayload = $this->isUrl($urlOrMediaId)
            ? ['link' => $urlOrMediaId]
            : ['id' => $urlOrMediaId];

        if ($filename) {
            $documentPayload['filename'] = $filename;
        }
        if ($caption) {
            $documentPayload['caption'] = $caption;
        }

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'document',
            'document' => $documentPayload,
        ]);
    }

    /**
     * Send a sticker message.
     */
    public function sendSticker(string $to, string $urlOrMediaId): array
    {
        $stickerPayload = $this->isUrl($urlOrMediaId)
            ? ['link' => $urlOrMediaId]
            : ['id' => $urlOrMediaId];

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'sticker',
            'sticker' => $stickerPayload,
        ]);
    }

    /**
     * Send a template message.
     */
    public function sendTemplate(string $to, string $templateName, array $components = [], string $language = 'pt_BR'): array
    {
        $templatePayload = [
            'name' => $templateName,
            'language' => ['code' => $language],
        ];

        if (! empty($components)) {
            $templatePayload['components'] = $components;
        }

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'template',
            'template' => $templatePayload,
        ]);
    }

    /**
     * Send interactive buttons.
     */
    public function sendButtons(string $to, string $body, array $buttons, ?string $header = null, ?string $footer = null): array
    {
        $interactive = [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => [
                'buttons' => array_map(fn ($button) => [
                    'type' => 'reply',
                    'reply' => [
                        'id' => $button['id'],
                        'title' => $button['title'],
                    ],
                ], $buttons),
            ],
        ];

        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => $footer];
        }

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * Send interactive list.
     */
    public function sendList(string $to, string $body, string $buttonText, array $sections, ?string $header = null, ?string $footer = null): array
    {
        $interactive = [
            'type' => 'list',
            'body' => ['text' => $body],
            'action' => [
                'button' => $buttonText,
                'sections' => array_map(fn ($section) => [
                    'title' => $section['title'],
                    'rows' => array_map(fn ($row) => array_filter([
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'description' => $row['description'] ?? null,
                    ]), $section['rows']),
                ], $sections),
            ],
        ];

        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => $footer];
        }

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * Send CTA URL button.
     */
    public function sendCtaUrl(string $to, string $body, string $buttonText, string $url, ?string $header = null, ?string $footer = null): array
    {
        $interactive = [
            'type' => 'cta_url',
            'body' => ['text' => $body],
            'action' => [
                'name' => 'cta_url',
                'parameters' => [
                    'display_text' => $buttonText,
                    'url' => $url,
                ],
            ],
        ];

        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => $footer];
        }

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * Send location.
     */
    public function sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null): array
    {
        $locationPayload = [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        if ($name) {
            $locationPayload['name'] = $name;
        }
        if ($address) {
            $locationPayload['address'] = $address;
        }

        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'location',
            'location' => $locationPayload,
        ]);
    }

    /**
     * Send contacts.
     */
    public function sendContacts(string $to, array $contacts): array
    {
        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'contacts',
            'contacts' => $contacts,
        ]);
    }

    /**
     * Send reaction to a message.
     */
    public function sendReaction(string $messageId, string $emoji): array
    {
        return $this->sendMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->phone->phone_number,
            'type' => 'reaction',
            'reaction' => [
                'message_id' => $messageId,
                'emoji' => $emoji,
            ],
        ]);
    }

    /**
     * Remove reaction from a message.
     */
    public function removeReaction(string $messageId): array
    {
        return $this->sendReaction($messageId, '');
    }

    /**
     * Mark a message as read.
     */
    public function markAsRead(string $messageId): array
    {
        $response = $this->http()->post($this->getMessagesEndpoint(), [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);

        return $response->json();
    }

    /**
     * Upload media to WhatsApp.
     */
    public function uploadMedia(string $filePath, string $mimeType): array
    {
        $response = $this->http()
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post($this->getMediaEndpoint(), [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

        if (! $response->successful()) {
            throw MessageSendException::mediaUploadFailed($response->json('error.message', 'Unknown error'));
        }

        return $response->json();
    }

    /**
     * Get the URL for a media file.
     */
    public function getMediaUrl(string $mediaId): string
    {
        $response = $this->http()->get("{$this->baseUrl}/{$this->apiVersion}/{$mediaId}");

        if (! $response->successful()) {
            throw MediaDownloadException::mediaNotFound($mediaId);
        }

        return $response->json('url');
    }

    /**
     * Download media content.
     */
    public function downloadMedia(string $mediaId): string
    {
        $mediaUrl = $this->getMediaUrl($mediaId);

        $response = Http::withToken($this->phone->access_token)
            ->timeout(60)
            ->get($mediaUrl);

        if (! $response->successful()) {
            throw MediaDownloadException::downloadFailed($mediaId, 'HTTP '.$response->status());
        }

        return $response->body();
    }

    /**
     * Delete media from WhatsApp.
     */
    public function deleteMedia(string $mediaId): bool
    {
        $response = $this->http()->delete("{$this->baseUrl}/{$this->apiVersion}/{$mediaId}");

        return $response->successful();
    }

    /**
     * Get templates for the business account.
     */
    public function getTemplates(?string $status = null): array
    {
        $params = [];
        if ($status) {
            $params['status'] = $status;
        }

        $response = $this->http()->get($this->getTemplatesEndpoint(), $params);

        return $response->json('data', []);
    }

    /**
     * Get a specific template.
     */
    public function getTemplate(string $templateName): array
    {
        $templates = $this->getTemplates();

        return collect($templates)->firstWhere('name', $templateName) ?? [];
    }

    /**
     * Get phone number info.
     */
    public function getPhoneNumberInfo(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/{$this->apiVersion}/{$this->phone->phone_id}");

        return $response->json();
    }

    /**
     * Send a message via the API.
     *
     * @param  array<string, mixed>  $payload
     * @return array{messages: array<int, array{id: string}>}
     *
     * @throws MessageSendException
     */
    protected function sendMessage(array $payload): array
    {
        $this->logOutgoing($payload);

        $response = $this->http()->post($this->getMessagesEndpoint(), $payload);

        if (! $response->successful()) {
            $error = $response->json('error', []);
            $code = $error['code'] ?? 0;
            $message = $error['message'] ?? 'Unknown error';

            // Log full error for debugging
            Log::error('WhatsApp API error', [
                'code' => $code,
                'message' => $message,
                'error' => $error,
                'fbtrace_id' => $error['fbtrace_id'] ?? null,
                'phone_id' => $this->phone->phone_id,
            ]);

            throw match ($code) {
                190 => MessageSendException::invalidAccessToken($message, $error),
                4 => MessageSendException::rateLimited($message, $error),
                100 => MessageSendException::invalidParameter($message, $error),
                200 => MessageSendException::permissionDenied($message, $error),
                368 => MessageSendException::temporarilyBlocked($message, $error),
                default => MessageSendException::apiError($message, $error),
            };
        }

        return $response->json();
    }

    /**
     * Get HTTP client with authentication.
     */
    protected function http(): PendingRequest
    {
        return Http::withToken($this->phone->access_token)
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * Get the messages endpoint URL.
     */
    protected function getMessagesEndpoint(): string
    {
        return "{$this->baseUrl}/{$this->apiVersion}/{$this->phone->phone_id}/messages";
    }

    /**
     * Get the media endpoint URL.
     */
    protected function getMediaEndpoint(): string
    {
        return "{$this->baseUrl}/{$this->apiVersion}/{$this->phone->phone_id}/media";
    }

    /**
     * Get the templates endpoint URL.
     */
    protected function getTemplatesEndpoint(): string
    {
        return "{$this->baseUrl}/{$this->apiVersion}/{$this->phone->business_account_id}/message_templates";
    }

    /**
     * Normalize phone number (remove + and spaces).
     */
    protected function normalizePhoneNumber(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? $phone;
    }

    /**
     * Check if string is a URL.
     */
    protected function isUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    /**
     * Log outgoing message.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function logOutgoing(array $payload): void
    {
        if (config('whatsapp.logging.enabled', true)) {
            $channel = config('whatsapp.logging.channel');
            $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

            $logger->debug('Outgoing WhatsApp message', [
                'phone_id' => $this->phone->phone_id,
                'to' => $payload['to'] ?? null,
                'type' => $payload['type'] ?? null,
            ]);
        }
    }
}
