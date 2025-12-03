<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Client;

interface WhatsAppClientInterface
{
    /**
     * Send a text message.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendText(string $to, string $message, bool $previewUrl = false): array;

    /**
     * Send an image message.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendImage(string $to, string $urlOrMediaId, ?string $caption = null): array;

    /**
     * Send a video message.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendVideo(string $to, string $urlOrMediaId, ?string $caption = null): array;

    /**
     * Send an audio message.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendAudio(string $to, string $urlOrMediaId): array;

    /**
     * Send a document message.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendDocument(string $to, string $urlOrMediaId, ?string $filename = null, ?string $caption = null): array;

    /**
     * Send a sticker message.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendSticker(string $to, string $urlOrMediaId): array;

    /**
     * Send a template message.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendTemplate(string $to, string $templateName, array $components = [], string $language = 'pt_BR'): array;

    /**
     * Send interactive buttons.
     *
     * @param  array<int, array{id: string, title: string}>  $buttons
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendButtons(string $to, string $body, array $buttons, ?string $header = null, ?string $footer = null): array;

    /**
     * Send interactive list.
     *
     * @param  array<int, array{title: string, rows: array<int, array{id: string, title: string, description?: string}>}>  $sections
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendList(string $to, string $body, string $buttonText, array $sections, ?string $header = null, ?string $footer = null): array;

    /**
     * Send CTA URL button.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendCtaUrl(string $to, string $body, string $buttonText, string $url, ?string $header = null, ?string $footer = null): array;

    /**
     * Send location.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null): array;

    /**
     * Send contacts.
     *
     * @param  array<int, array<string, mixed>>  $contacts
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendContacts(string $to, array $contacts): array;

    /**
     * Send reaction to a message.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function sendReaction(string $messageId, string $emoji): array;

    /**
     * Remove reaction from a message.
     *
     * @return array{messages: array<int, array{id: string}>}
     */
    public function removeReaction(string $messageId): array;

    /**
     * Mark a message as read.
     *
     * @return array{success: bool}
     */
    public function markAsRead(string $messageId): array;

    /**
     * Start typing indicator and mark message as read.
     *
     * @param  string  $messageId  The WhatsApp message ID to mark as read
     * @return array<string, mixed>
     */
    public function startTyping(string $messageId): array;

    /**
     * Upload media to WhatsApp.
     *
     * @return array{id: string}
     */
    public function uploadMedia(string $filePath, string $mimeType): array;

    /**
     * Get the URL for a media file.
     */
    public function getMediaUrl(string $mediaId): string;

    /**
     * Download media content.
     */
    public function downloadMedia(string $mediaId): string;

    /**
     * Delete media from WhatsApp.
     */
    public function deleteMedia(string $mediaId): bool;

    /**
     * Get templates for the business account.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTemplates(?string $status = null): array;

    /**
     * Get a specific template.
     *
     * @return array<string, mixed>
     */
    public function getTemplate(string $templateName): array;

    /**
     * Get phone number info.
     *
     * @return array<string, mixed>
     */
    public function getPhoneNumberInfo(): array;
}
