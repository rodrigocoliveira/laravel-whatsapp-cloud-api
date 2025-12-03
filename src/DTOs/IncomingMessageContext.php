<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\DTOs;

use Illuminate\Support\Collection;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Support\MessageBuilder;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

readonly class IncomingMessageContext
{
    /**
     * @param  Collection<int, WhatsAppMessage>  $messages
     */
    public function __construct(
        public WhatsAppPhone $phone,
        public WhatsAppConversation $conversation,
        public WhatsAppMessageBatch $batch,
        public Collection $messages,
    ) {}

    /**
     * Get combined text from all text messages in batch.
     */
    public function getTextContent(): string
    {
        return $this->messages
            ->filter(fn (WhatsAppMessage $message) => $message->isText())
            ->pluck('text_body')
            ->filter()
            ->implode("\n");
    }

    /**
     * Get combined text including captions and transcriptions.
     */
    public function getFullTextContent(): string
    {
        $parts = [];

        foreach ($this->messages as $message) {
            if ($message->isText()) {
                $parts[] = $message->text_body;
            }

            // Add captions from media messages
            $content = $message->content ?? [];
            if (isset($content['caption']) && $content['caption']) {
                $parts[] = $content['caption'];
            }

            // Add transcriptions from audio messages
            if ($message->hasTranscription()) {
                $parts[] = $message->transcription;
            }
        }

        return implode("\n", array_filter($parts));
    }

    /**
     * Get all media files with local paths.
     *
     * @return Collection<int, WhatsAppMessage>
     */
    public function getMedia(): Collection
    {
        return $this->messages->filter(fn (WhatsAppMessage $message) => $message->isMedia() && $message->local_media_path !== null
        );
    }

    /**
     * Get all audio transcriptions.
     *
     * @return Collection<int, string>
     */
    public function getTranscriptions(): Collection
    {
        return $this->messages
            ->filter(fn (WhatsAppMessage $message) => $message->hasTranscription())
            ->pluck('transcription');
    }

    /**
     * Check if any audio message failed transcription.
     */
    public function hasFailedTranscriptions(): bool
    {
        return $this->messages->contains(
            fn (WhatsAppMessage $message) => $message->transcription_status === WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED
        );
    }

    /**
     * Get audio messages that failed transcription.
     *
     * @return Collection<int, WhatsAppMessage>
     */
    public function getFailedTranscriptions(): Collection
    {
        return $this->messages->filter(
            fn (WhatsAppMessage $message) => $message->transcription_status === WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED
        );
    }

    /**
     * Check if any media message failed to download.
     */
    public function hasFailedMediaDownloads(): bool
    {
        return $this->messages->contains(
            fn (WhatsAppMessage $message) => $message->media_status === WhatsAppMessage::MEDIA_STATUS_FAILED
        );
    }

    /**
     * Get media messages that failed to download.
     *
     * @return Collection<int, WhatsAppMessage>
     */
    public function getFailedMediaDownloads(): Collection
    {
        return $this->messages->filter(
            fn (WhatsAppMessage $message) => $message->media_status === WhatsAppMessage::MEDIA_STATUS_FAILED
        );
    }

    /**
     * Check if any message has processing errors (failed media download or transcription).
     */
    public function hasProcessingErrors(): bool
    {
        return $this->hasFailedMediaDownloads() || $this->hasFailedTranscriptions();
    }

    /**
     * Get all messages with processing errors (failed media download or transcription).
     *
     * @return Collection<int, WhatsAppMessage>
     */
    public function getProcessingErrors(): Collection
    {
        return $this->messages->filter(
            fn (WhatsAppMessage $message) => $message->media_status === WhatsAppMessage::MEDIA_STATUS_FAILED
                || $message->transcription_status === WhatsAppMessage::TRANSCRIPTION_STATUS_FAILED
        );
    }

    /**
     * Get messages of specific type.
     *
     * @return Collection<int, WhatsAppMessage>
     */
    public function getMessagesByType(string $type): Collection
    {
        return $this->messages->filter(fn (WhatsAppMessage $message) => $message->type === $type);
    }

    /**
     * Get location messages.
     *
     * @return Collection<int, WhatsAppMessage>
     */
    public function getLocations(): Collection
    {
        return $this->getMessagesByType(WhatsAppMessage::TYPE_LOCATION);
    }

    /**
     * Get interactive reply messages (button/list selections).
     *
     * @return Collection<int, WhatsAppMessage>
     */
    public function getInteractiveReplies(): Collection
    {
        return $this->messages->filter(fn (WhatsAppMessage $message) => $message->isInteractiveReply());
    }

    /**
     * Get contact card messages.
     *
     * @return Collection<int, WhatsAppMessage>
     */
    public function getContacts(): Collection
    {
        return $this->getMessagesByType(WhatsAppMessage::TYPE_CONTACTS);
    }

    /**
     * Get reaction messages.
     *
     * @return Collection<int, WhatsAppMessage>
     */
    public function getReactions(): Collection
    {
        return $this->getMessagesByType(WhatsAppMessage::TYPE_REACTION);
    }

    /**
     * Check if batch contains specific message type.
     */
    public function hasMessageType(string $type): bool
    {
        return $this->messages->contains(fn (WhatsAppMessage $message) => $message->type === $type);
    }

    /**
     * Get the first message in the batch.
     */
    public function getFirstMessage(): ?WhatsAppMessage
    {
        return $this->messages->first();
    }

    /**
     * Get the last message in the batch.
     */
    public function getLastMessage(): ?WhatsAppMessage
    {
        return $this->messages->last();
    }

    /**
     * Get the message count.
     */
    public function getMessageCount(): int
    {
        return $this->messages->count();
    }

    /**
     * Check if the batch has only one message.
     */
    public function isSingleMessage(): bool
    {
        return $this->messages->count() === 1;
    }

    /**
     * Reply with text message.
     */
    public function reply(string $text): WhatsAppMessage
    {
        return $this->replyWith()->text($text)->send();
    }

    /**
     * Get reply builder for fluent API.
     */
    public function replyWith(): MessageBuilder
    {
        /** @var WhatsAppManager $manager */
        $manager = app(WhatsAppManager::class);

        return $manager
            ->phone($this->phone->key)
            ->to($this->conversation->contact_phone);
    }

    /**
     * Get the handler config value.
     *
     * @param  mixed  $default
     * @return mixed
     */
    public function getHandlerConfig(string $key, $default = null)
    {
        $config = $this->phone->handler_config ?? [];

        return $config[$key] ?? $default;
    }

    /**
     * Start typing indicator for the most recent message.
     *
     * Call this when you're about to start processing/generating a response.
     * The typing indicator will be dismissed once you send a message, or after 25 seconds.
     *
     * @return array{success: bool}|null Returns null if no messages in batch
     */
    public function startTyping(): ?array
    {
        $lastMessage = $this->getLastMessage();

        if (! $lastMessage) {
            return null;
        }

        $client = new WhatsAppClient($this->phone);

        return $client->startTyping($lastMessage->message_id);
    }

    /**
     * Mark the most recent message as read (without typing indicator).
     *
     * @return array{success: bool}|null Returns null if no messages in batch
     */
    public function markAsRead(): ?array
    {
        $lastMessage = $this->getLastMessage();

        if (! $lastMessage) {
            return null;
        }

        $client = new WhatsAppClient($this->phone);

        return $client->markAsRead($lastMessage->message_id);
    }
}
