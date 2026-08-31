<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Multek\LaravelWhatsAppCloud\Http\Controllers\FlowEndpointController;
use Multek\LaravelWhatsAppCloud\Http\Controllers\WebhookController;
use Multek\LaravelWhatsAppCloud\Http\Middleware\VerifyWhatsAppSignature;

$webhookPath = config('whatsapp.webhook.path', 'webhooks/whatsapp');
$middleware = config('whatsapp.webhook.middleware', ['api']);

Route::middleware($middleware)
    ->prefix($webhookPath)
    ->group(function () {
        // Webhook verification (GET)
        Route::get('/', [WebhookController::class, 'verify'])
            ->name('whatsapp.webhook.verify');

        // Webhook handler (POST)
        Route::post('/', [WebhookController::class, 'handle'])
            ->middleware(VerifyWhatsAppSignature::class)
            ->name('whatsapp.webhook.handle');
    });

// Flow data-exchange endpoint. It is guarded at runtime by
// `whatsapp.flows.endpoint_enabled` and authenticated by the payload encryption,
// so it deliberately skips the webhook signature middleware.
Route::middleware($middleware)
    ->post($webhookPath.'/'.ltrim((string) config('whatsapp.flows.endpoint_path', 'flow'), '/'), FlowEndpointController::class)
    ->name('whatsapp.flow.exchange');
