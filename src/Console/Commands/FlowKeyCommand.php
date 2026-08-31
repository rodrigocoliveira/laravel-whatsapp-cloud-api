<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

/**
 * Manage the RSA key pair backing endpoint-driven WhatsApp Flows.
 */
class FlowKeyCommand extends Command
{
    protected $signature = 'whatsapp:flow-key
                            {action : generate or upload}
                            {--phone= : Phone key to upload the public key for}
                            {--bits=2048 : Key size used by generate}';

    protected $description = 'Generate the WhatsApp Flow key pair or upload the public key to Meta';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'generate' => $this->generate(),
            'upload' => $this->upload(),
            default => $this->invalidAction(),
        };
    }

    private function invalidAction(): int
    {
        $this->error("Unknown action '{$this->argument('action')}'. Use generate or upload.");

        return self::FAILURE;
    }

    private function generate(): int
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => (int) $this->option('bits'),
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            $this->error('Could not generate a key pair.');

            return self::FAILURE;
        }

        openssl_pkey_export($resource, $privateKey);

        $this->info('Private key — store it in WHATSAPP_FLOW_PRIVATE_KEY, never in git:');
        $this->line($privateKey);

        $this->info('Public key — upload it with: php artisan whatsapp:flow-key upload --phone=<key>');
        $this->line(openssl_pkey_get_details($resource)['key']);

        return self::SUCCESS;
    }

    private function upload(): int
    {
        $privateKey = $this->resolvePrivateKey();

        if ($privateKey === null) {
            $this->error('No usable private key. Set whatsapp.flows.private_key first.');

            return self::FAILURE;
        }

        $phone = $this->resolvePhone();

        if ($phone === null) {
            return self::FAILURE;
        }

        $key = openssl_pkey_get_private($privateKey, config('whatsapp.flows.private_key_passphrase'));

        if ($key === false) {
            $this->error('The configured private key could not be read.');

            return self::FAILURE;
        }

        $publicKey = openssl_pkey_get_details($key)['key'];

        $response = Http::withToken($phone->access_token)
            ->acceptJson()
            ->timeout(30)
            ->asForm()
            ->post(sprintf(
                '%s/%s/%s/whatsapp_business_encryption',
                config('whatsapp.api_base_url', 'https://graph.facebook.com'),
                config('whatsapp.api_version', 'v24.0'),
                $phone->phone_id
            ), ['business_public_key' => $publicKey]);

        if (! $response->successful()) {
            $this->error('Meta rejected the public key: '.($response->json('error.message') ?? $response->body()));

            return self::FAILURE;
        }

        $this->info("Public key uploaded for phone '{$phone->key}'.");

        return self::SUCCESS;
    }

    private function resolvePrivateKey(): ?string
    {
        $privateKey = config('whatsapp.flows.private_key');

        if (! is_string($privateKey) || $privateKey === '') {
            return null;
        }

        // The config may point at a key file instead of inlining the PEM.
        if (! str_contains($privateKey, 'PRIVATE KEY') && is_readable($privateKey)) {
            return (string) file_get_contents($privateKey);
        }

        return $privateKey;
    }

    private function resolvePhone(): ?WhatsAppPhone
    {
        $phoneKey = $this->option('phone');

        if (! is_string($phoneKey) || $phoneKey === '') {
            $this->error('Pass the phone to upload for: --phone=<key>');

            return null;
        }

        $phone = WhatsAppPhone::where('key', $phoneKey)->first();

        if ($phone === null) {
            $this->error("No phone found with key '{$phoneKey}'.");
        }

        return $phone;
    }
}
