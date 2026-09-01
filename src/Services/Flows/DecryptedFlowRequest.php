<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Services\Flows;

/**
 * A decrypted data-exchange request, carrying the material needed to answer it.
 */
readonly class DecryptedFlowRequest
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public array $body,
        public string $aesKey,
        public string $initialVector,
    ) {}
}
