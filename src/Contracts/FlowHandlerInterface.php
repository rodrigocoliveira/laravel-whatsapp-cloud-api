<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Contracts;

use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowRequest;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowResponse;

/**
 * Decides what a WhatsApp Flow shows next when a screen calls back to the server.
 *
 * Health checks (`ping`) and client error notifications are answered by the package
 * and never reach this handler.
 */
interface FlowHandlerInterface
{
    public function handle(FlowRequest $request): FlowResponse;
}
