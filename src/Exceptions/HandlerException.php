<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

class HandlerException extends WhatsAppException
{
    public static function handlerNotFound(string $handlerClass): self
    {
        return new self("Handler class '{$handlerClass}' not found.");
    }

    public static function invalidHandler(string $handlerClass): self
    {
        return new self("Handler '{$handlerClass}' does not implement MessageHandlerInterface.");
    }

    public static function handlerFailed(string $handlerClass, string $reason): self
    {
        return new self("Handler '{$handlerClass}' failed: {$reason}");
    }

    public static function noHandlerConfigured(string $phoneKey): self
    {
        return new self("No handler configured for phone '{$phoneKey}'.");
    }
}
