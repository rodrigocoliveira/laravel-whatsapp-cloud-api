<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud;

use Illuminate\Contracts\Foundation\Application;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Exceptions\InvalidPhoneException;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Support\MessageBuilder;

class WhatsAppManager
{
    protected ?WhatsAppPhone $currentPhone = null;

    protected ?WhatsAppClientInterface $client = null;

    public function __construct(
        protected Application $app,
    ) {}

    /**
     * Set the phone to use for sending messages.
     *
     * @throws InvalidPhoneException
     */
    public function phone(string $key): self
    {
        $phone = WhatsAppPhone::where('key', $key)
            ->where('is_active', true)
            ->first();

        if (! $phone) {
            throw InvalidPhoneException::notFound($key);
        }

        $this->currentPhone = $phone;
        $this->client = new WhatsAppClient($phone);

        return $this;
    }

    /**
     * Get a phone by its phone_id (WhatsApp Phone Number ID).
     *
     * @throws InvalidPhoneException
     */
    public function phoneById(string $phoneId): self
    {
        $phone = WhatsAppPhone::where('phone_id', $phoneId)
            ->where('is_active', true)
            ->first();

        if (! $phone) {
            throw InvalidPhoneException::phoneIdNotFound($phoneId);
        }

        $this->currentPhone = $phone;
        $this->client = new WhatsAppClient($phone);

        return $this;
    }

    /**
     * Get a MessageBuilder targeting a specific phone number.
     */
    public function to(string $phone): MessageBuilder
    {
        $this->ensurePhoneSelected();

        return (new MessageBuilder($this->currentPhone, $this->client))->to($phone);
    }

    /**
     * Send a text message directly.
     */
    public function sendText(string $to, string $message, bool $previewUrl = false): WhatsAppMessage
    {
        return $this->to($to)->text($message)->previewUrl($previewUrl)->send();
    }

    /**
     * Send an image message directly.
     */
    public function sendImage(string $to, string $urlOrMediaId, ?string $caption = null): WhatsAppMessage
    {
        $builder = $this->to($to)->image($urlOrMediaId);
        if ($caption) {
            $builder->caption($caption);
        }

        return $builder->send();
    }

    /**
     * Send a video message directly.
     */
    public function sendVideo(string $to, string $urlOrMediaId, ?string $caption = null): WhatsAppMessage
    {
        $builder = $this->to($to)->video($urlOrMediaId);
        if ($caption) {
            $builder->caption($caption);
        }

        return $builder->send();
    }

    /**
     * Send an audio message directly.
     */
    public function sendAudio(string $to, string $urlOrMediaId): WhatsAppMessage
    {
        return $this->to($to)->audio($urlOrMediaId)->send();
    }

    /**
     * Send a document message directly.
     */
    public function sendDocument(string $to, string $urlOrMediaId, ?string $filename = null, ?string $caption = null): WhatsAppMessage
    {
        $builder = $this->to($to)->document($urlOrMediaId);
        if ($filename) {
            $builder->filename($filename);
        }
        if ($caption) {
            $builder->caption($caption);
        }

        return $builder->send();
    }

    /**
     * Send a sticker message directly.
     */
    public function sendSticker(string $to, string $urlOrMediaId): WhatsAppMessage
    {
        return $this->to($to)->sticker($urlOrMediaId)->send();
    }

    /**
     * Send a location message directly.
     */
    public function sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null): WhatsAppMessage
    {
        return $this->to($to)->location($latitude, $longitude, $name, $address)->send();
    }

    /**
     * Send contacts directly.
     *
     * @param  array<int, array<string, mixed>>  $contacts
     */
    public function sendContacts(string $to, array $contacts): WhatsAppMessage
    {
        return $this->to($to)->contacts($contacts)->send();
    }

    /**
     * Send a reaction to a message.
     */
    public function sendReaction(string $messageId, string $emoji): WhatsAppMessage
    {
        $this->ensurePhoneSelected();

        return (new MessageBuilder($this->currentPhone, $this->client))
            ->reaction($messageId, $emoji)
            ->send();
    }

    /**
     * Remove a reaction from a message.
     */
    public function removeReaction(string $messageId): WhatsAppMessage
    {
        return $this->sendReaction($messageId, '');
    }

    /**
     * Mark a message as read.
     *
     * @return array{success: bool}
     */
    public function markAsRead(string $messageId): array
    {
        $this->ensurePhoneSelected();

        return $this->client->markAsRead($messageId);
    }

    /**
     * Get the current phone.
     */
    public function getCurrentPhone(): ?WhatsAppPhone
    {
        return $this->currentPhone;
    }

    /**
     * Get the current client.
     */
    public function getClient(): ?WhatsAppClientInterface
    {
        return $this->client;
    }

    /**
     * Ensure a phone has been selected.
     *
     * @throws InvalidPhoneException
     */
    protected function ensurePhoneSelected(): void
    {
        if ($this->currentPhone === null || $this->client === null) {
            // Try to get the first active phone as default
            $phone = WhatsAppPhone::where('is_active', true)->first();

            if (! $phone) {
                throw InvalidPhoneException::noPhoneConfigured();
            }

            $this->currentPhone = $phone;
            $this->client = new WhatsAppClient($phone);
        }
    }
}
