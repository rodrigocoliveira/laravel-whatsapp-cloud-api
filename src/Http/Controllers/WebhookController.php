<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Multek\LaravelWhatsAppCloud\Exceptions\WebhookVerificationException;
use Multek\LaravelWhatsAppCloud\Support\WebhookProcessor;

class WebhookController extends Controller
{
    public function __construct(
        protected WebhookProcessor $processor,
    ) {}

    /**
     * Handle webhook verification (GET request).
     *
     * @throws WebhookVerificationException
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe') {
            throw WebhookVerificationException::invalidMode($mode ?? 'null');
        }

        if (empty($token)) {
            throw WebhookVerificationException::missingVerifyToken();
        }

        $expectedToken = config('whatsapp.webhook.verify_token');

        if ($token !== $expectedToken) {
            throw WebhookVerificationException::invalidVerifyToken();
        }

        return response($challenge, 200);
    }

    /**
     * Handle incoming webhook events (POST request).
     */
    public function handle(Request $request): Response
    {
        $payload = $request->all();

        // Process the webhook asynchronously to respond quickly
        $this->processor->process($payload);

        // Always respond with 200 OK quickly
        return response('EVENT_RECEIVED', 200);
    }
}
