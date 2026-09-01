<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Multek\LaravelWhatsAppCloud\Contracts\FlowHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowRequest;
use Multek\LaravelWhatsAppCloud\Exceptions\FlowEncryptionException;
use Multek\LaravelWhatsAppCloud\Services\Flows\FlowEncryptionService;

/**
 * The data-exchange endpoint for endpoint-backed WhatsApp Flows.
 *
 * Authenticity comes from the encryption itself: only Meta holds the public key
 * registered for this business, so there is no signature to verify here.
 */
class FlowEndpointController extends Controller
{
    private const DEFAULT_VERSION = '3.0';

    public function __invoke(Request $request): Response
    {
        if (! config('whatsapp.flows.endpoint_enabled', false)) {
            abort(404);
        }

        try {
            $encryption = $this->encryptionService();
            $decrypted = $encryption->decrypt($request->all());
        } catch (FlowEncryptionException $exception) {
            // 421 makes Meta refresh the business public key and retry.
            Log::warning('WhatsApp Flow request could not be decrypted', [
                'reason' => $exception->getMessage(),
            ]);

            return response('', 421);
        }

        $flowRequest = FlowRequest::fromArray($decrypted->body);
        $version = $flowRequest->version ?? self::DEFAULT_VERSION;

        try {
            $response = $this->respondTo($flowRequest, $version);
        } catch (\Throwable $exception) {
            Log::error('WhatsApp Flow handler failed', [
                'action' => $flowRequest->action,
                'screen' => $flowRequest->screen,
                'exception' => $exception->getMessage(),
            ]);

            return response('', 500);
        }

        return response($encryption->encrypt($response, $decrypted), 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * @return array<string, mixed>
     */
    private function respondTo(FlowRequest $request, string $version): array
    {
        if ($request->isPing()) {
            return ['data' => ['status' => 'active']];
        }

        if ($request->isErrorNotification()) {
            Log::warning('WhatsApp Flow client error', [
                'action' => $request->action,
                'screen' => $request->screen,
                'flow_token' => $request->flowToken,
                'error' => $request->errorMessage(),
            ]);

            return ['data' => ['acknowledged' => true]];
        }

        return $this->handler()->handle($request)->toArray($version);
    }

    private function encryptionService(): FlowEncryptionService
    {
        $privateKey = config('whatsapp.flows.private_key');

        if (! is_string($privateKey) || $privateKey === '') {
            throw FlowEncryptionException::invalidPrivateKey();
        }

        // Allow pointing the config at a key file instead of inlining the PEM.
        if (! str_contains($privateKey, 'PRIVATE KEY') && is_readable($privateKey)) {
            $privateKey = (string) file_get_contents($privateKey);
        }

        return new FlowEncryptionService(
            $privateKey,
            config('whatsapp.flows.private_key_passphrase')
        );
    }

    private function handler(): FlowHandlerInterface
    {
        $handlerClass = config('whatsapp.flows.handler');

        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            throw new \RuntimeException('No WhatsApp Flow handler is configured.');
        }

        $handler = app($handlerClass);

        if (! $handler instanceof FlowHandlerInterface) {
            throw new \RuntimeException("{$handlerClass} must implement FlowHandlerInterface.");
        }

        return $handler;
    }
}
