<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Multek\LaravelWhatsAppCloud\Contracts\FlowHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowRequest;
use Multek\LaravelWhatsAppCloud\Exceptions\FlowEncryptionException;
use Multek\LaravelWhatsAppCloud\Exceptions\FlowTokenException;
use Multek\LaravelWhatsAppCloud\Exceptions\WebhookVerificationException;
use Multek\LaravelWhatsAppCloud\Http\Middleware\VerifyWhatsAppSignature;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppFlowSession;
use Multek\LaravelWhatsAppCloud\Services\Flows\FlowEncryptionService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The data-exchange endpoint for endpoint-backed WhatsApp Flows.
 *
 * Meta signs every request (`X-Hub-Signature-256`, answered with 432 on mismatch) and
 * encrypts it with the business public key (421 when it cannot be decrypted).
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
            VerifyWhatsAppSignature::verify($request);
        } catch (WebhookVerificationException $exception) {
            Log::warning('WhatsApp Flow request signature rejected', [
                'reason' => $exception->getMessage(),
            ]);

            return response('', 432);
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

        $flowRequest = FlowRequest::fromArray($decrypted->body, $this->findSession($decrypted->body));
        $version = $flowRequest->version ?? self::DEFAULT_VERSION;

        try {
            $response = $this->respondTo($flowRequest, $version);
        } catch (FlowTokenException $exception) {
            // Not encrypted: Meta reads error_msg from a plain JSON body.
            return response()->json(['error_msg' => $exception->getMessage()], 427);
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

        $session = $request->session();

        if ($session !== null) {
            $this->ensureSessionIsOpen($session);
        }

        $response = $this->handler($session)->handle($request);

        if ($session !== null && $response->isComplete()) {
            $session->markAsCompleted($response->completionParams());
        }

        return $response->toArray($version);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function findSession(array $body): ?WhatsAppFlowSession
    {
        $token = $body['flow_token'] ?? null;

        return is_string($token) && $token !== ''
            ? WhatsAppFlowSession::with('flow')->firstWhere('flow_token', $token)
            : null;
    }

    /**
     * @throws FlowTokenException
     */
    private function ensureSessionIsOpen(WhatsAppFlowSession $session): void
    {
        $message = config('whatsapp.flows.token_no_longer_valid_message');
        $refuse = fn () => is_string($message) && $message !== ''
            ? FlowTokenException::noLongerValid($message)
            : FlowTokenException::noLongerValid();

        if ($session->isExpired()) {
            $session->update(['status' => WhatsAppFlowSession::STATUS_EXPIRED]);

            throw $refuse();
        }

        if ($session->isCompleted() && $session->isSingleUse()) {
            throw $refuse();
        }

        if ($session->status === WhatsAppFlowSession::STATUS_SENT) {
            $session->update(['status' => WhatsAppFlowSession::STATUS_IN_PROGRESS]);
        }
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

    /**
     * The flow's own handler, then the sending phone's `flow_handler`, then `whatsapp.flows.handler`.
     */
    private function handler(?WhatsAppFlowSession $session): FlowHandlerInterface
    {
        $handlerClass = $session->flow->handler
            ?? $session?->message->phone->flow_handler
            ?? config('whatsapp.flows.handler');

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
