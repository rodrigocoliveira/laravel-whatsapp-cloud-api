<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

/**
 * Throw from a flow handler to answer HTTP 427: the client shows the message and
 * disables the flow's CTA button. Send a new flow message to let the user start over.
 */
class FlowTokenException extends WhatsAppException
{
    public static function noLongerValid(string $message = 'This form is no longer available.'): self
    {
        return new self($message);
    }
}
