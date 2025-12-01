<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

use Exception;

class WhatsAppException extends Exception
{
    protected ?array $errorData = null;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, ?array $errorData = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorData = $errorData;
    }

    public function getErrorData(): ?array
    {
        return $this->errorData;
    }
}
