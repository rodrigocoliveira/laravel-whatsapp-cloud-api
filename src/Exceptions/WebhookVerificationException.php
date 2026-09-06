<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookVerificationException extends WhatsAppException
{
    public function render(Request $request): Response
    {
        return response($this->getMessage(), 403);
    }

    public static function invalidVerifyToken(): self
    {
        return new self('Invalid webhook verify token.');
    }

    public static function invalidSignature(): self
    {
        return new self('Invalid webhook signature. Request may have been tampered with.');
    }

    public static function missingSignature(): self
    {
        return new self('Missing X-Hub-Signature-256 header.');
    }

    public static function missingVerifyToken(): self
    {
        return new self('Missing hub.verify_token parameter.');
    }

    public static function invalidMode(string $mode): self
    {
        return new self("Invalid hub.mode: {$mode}. Expected 'subscribe'.");
    }
}
