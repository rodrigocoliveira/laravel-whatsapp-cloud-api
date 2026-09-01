<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Services\Flows\FlowEncryptionService;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;
use Throwable;

/**
 * Smoke-test Flows against real traffic.
 *
 * Automated tests fake the Graph API, so they cannot catch a rejected token, a
 * wrong API version or an unpublished flow. This command exercises the real thing.
 */
class FlowTestCommand extends Command
{
    protected $signature = 'whatsapp:flow-test
                            {action=send : send (deliver a flow message) or ping (call the local endpoint)}
                            {--phone= : Phone key to send from}
                            {--flow-id= : The flow to send}
                            {--to= : Recipient phone number}
                            {--cta=Open : Text on the flow button}
                            {--body=Flow smoke test : Message body}
                            {--screen= : Initial screen name}
                            {--mode=draft : draft or published}
                            {--data-exchange : Use flow_action=data_exchange instead of navigate}';

    protected $description = 'Send a real WhatsApp Flow message, or ping the local flow endpoint';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'send' => $this->send(),
            'ping' => $this->ping(),
            default => $this->invalidAction(),
        };
    }

    private function invalidAction(): int
    {
        $this->error("Unknown action '{$this->argument('action')}'. Use send or ping.");

        return self::FAILURE;
    }

    private function send(): int
    {
        foreach (['phone', 'flow-id', 'to'] as $required) {
            if (! $this->option($required)) {
                $this->error("--{$required} is required for send.");

                return self::FAILURE;
            }
        }

        $phoneKey = (string) $this->option('phone');

        if (! WhatsAppPhone::where('key', $phoneKey)->exists()) {
            $this->error("No phone found with key '{$phoneKey}'.");

            return self::FAILURE;
        }

        $builder = app(WhatsAppManager::class)
            ->phone($phoneKey)
            ->to((string) $this->option('to'))
            ->flow(
                (string) $this->option('body'),
                (string) $this->option('flow-id'),
                (string) $this->option('cta')
            )
            ->flowMode((string) $this->option('mode'));

        if ($this->option('data-exchange')) {
            $builder->flowDataExchange();
        }

        if ($screen = $this->option('screen')) {
            $builder->flowScreen((string) $screen);
        }

        try {
            $message = $builder->send();
        } catch (Throwable $exception) {
            $this->error('Meta rejected the flow message: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Flow message accepted by Meta: {$message->message_id}");
        $this->line('flow_token: '.($message->content['flow_token'] ?? '—'));
        $this->line('Watch for the nfm_reply webhook once the form is submitted.');

        return self::SUCCESS;
    }

    /**
     * Encrypt a health check the way Meta does and post it to our own endpoint.
     *
     * This proves the private key, the route and the response encryption line up
     * without needing Meta to call us.
     */
    private function ping(): int
    {
        $privateKey = $this->resolvePrivateKey();

        if ($privateKey === null) {
            $this->error('No usable private key. Set whatsapp.flows.private_key first.');

            return self::FAILURE;
        }

        $key = openssl_pkey_get_private($privateKey, config('whatsapp.flows.private_key_passphrase'));

        if ($key === false) {
            $this->error('The configured private key could not be read.');

            return self::FAILURE;
        }

        $aesKey = random_bytes(16);
        $initialVector = random_bytes(16);

        openssl_public_encrypt(
            $this->oaepPad($aesKey, openssl_pkey_get_details($key)['bits'] / 8),
            $encryptedKey,
            openssl_pkey_get_details($key)['key'],
            OPENSSL_NO_PADDING
        );

        $ciphertext = openssl_encrypt(
            (string) json_encode(['version' => '3.0', 'action' => 'ping']),
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $initialVector,
            $tag,
            '',
            16
        );

        $url = rtrim((string) config('app.url'), '/').'/'
            .trim((string) config('whatsapp.webhook.path', 'webhooks/whatsapp'), '/').'/'
            .trim((string) config('whatsapp.flows.endpoint_path', 'flow'), '/');

        $this->line("Pinging {$url} ...");

        $response = Http::timeout(30)->post($url, [
            'encrypted_flow_data' => base64_encode($ciphertext.$tag),
            'encrypted_aes_key' => base64_encode($encryptedKey),
            'initial_vector' => base64_encode($initialVector),
        ]);

        if (! $response->successful()) {
            $this->error("The endpoint answered {$response->status()}.");
            $this->line($response->status() === 421
                ? 'A 421 means it could not decrypt the request — the configured key does not match.'
                : 'A 404 means the endpoint is disabled; a 500 means the handler failed.');

            return self::FAILURE;
        }

        $plaintext = openssl_decrypt(
            substr(base64_decode($response->body()), 0, -16),
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $initialVector ^ str_repeat("\xff", strlen($initialVector)),
            substr(base64_decode($response->body()), -16)
        );

        if ($plaintext === false) {
            $this->error('The endpoint answered 200 but the response could not be decrypted.');

            return self::FAILURE;
        }

        $this->info('Endpoint healthy. Decrypted response: '.$plaintext);

        return self::SUCCESS;
    }

    /**
     * EME-OAEP encoding with an empty label, mirroring the decoding in
     * {@see FlowEncryptionService}. ext-openssl cannot pick the OAEP digest.
     */
    private function oaepPad(string $message, int $keyLength): string
    {
        $labelHash = hash('sha256', '', true);
        $hashLength = strlen($labelHash);

        $padding = str_repeat("\x00", $keyLength - strlen($message) - 2 * $hashLength - 2);
        $block = $labelHash.$padding."\x01".$message;
        $seed = random_bytes($hashLength);

        $maskedBlock = $block ^ $this->mgf1($seed, strlen($block));
        $maskedSeed = $seed ^ $this->mgf1($maskedBlock, $hashLength);

        return "\x00".$maskedSeed.$maskedBlock;
    }

    private function mgf1(string $seed, int $length): string
    {
        $mask = '';

        for ($counter = 0; strlen($mask) < $length; $counter++) {
            $mask .= hash('sha256', $seed.pack('N', $counter), true);
        }

        return substr($mask, 0, $length);
    }

    private function resolvePrivateKey(): ?string
    {
        $privateKey = config('whatsapp.flows.private_key');

        if (! is_string($privateKey) || $privateKey === '') {
            return null;
        }

        if (! str_contains($privateKey, 'PRIVATE KEY') && is_readable($privateKey)) {
            return (string) file_get_contents($privateKey);
        }

        return $privateKey;
    }
}
