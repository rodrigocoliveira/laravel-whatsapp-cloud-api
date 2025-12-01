<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Multek\LaravelWhatsAppCloud\Exceptions\WebhookVerificationException;
use Symfony\Component\HttpFoundation\Response;

class VerifyWhatsAppSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): Response  $next
     *
     * @throws WebhookVerificationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip signature verification for GET requests (webhook verification)
        if ($request->isMethod('GET')) {
            return $next($request);
        }

        $appSecret = config('whatsapp.webhook.app_secret');

        // If no app secret is configured, skip verification
        if (empty($appSecret)) {
            return $next($request);
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (empty($signature)) {
            throw WebhookVerificationException::missingSignature();
        }

        // Extract the hash from the signature
        $signatureParts = explode('=', $signature, 2);
        if (count($signatureParts) !== 2 || $signatureParts[0] !== 'sha256') {
            throw WebhookVerificationException::invalidSignature();
        }

        $receivedHash = $signatureParts[1];

        // Calculate expected hash
        $payload = $request->getContent();
        $expectedHash = hash_hmac('sha256', $payload, $appSecret);

        // Compare hashes
        if (! hash_equals($expectedHash, $receivedHash)) {
            throw WebhookVerificationException::invalidSignature();
        }

        return $next($request);
    }
}
