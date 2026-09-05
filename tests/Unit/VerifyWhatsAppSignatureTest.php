<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Multek\LaravelWhatsAppCloud\Http\Middleware\VerifyWhatsAppSignature;

it('logs a warning and still lets the request through when app_secret is empty', function () {
    config(['whatsapp.webhook.app_secret' => '']);

    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/signature verification is disabled/i'));

    $middleware = new VerifyWhatsAppSignature;
    $request = Request::create('/webhooks/whatsapp', 'POST');

    $response = $middleware->handle($request, fn ($req) => new Response('handled'));

    expect($response->getContent())->toBe('handled');
});
