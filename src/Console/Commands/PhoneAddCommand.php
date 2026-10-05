<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

class PhoneAddCommand extends PhoneCommand
{
    protected $signature = 'whatsapp:phone:add
                            {key : Unique key used to select the phone, e.g. support}
                            {--phone-id= : Meta phone number ID}
                            {--phone-number= : Number in E.164 format, e.g. +5511999999999}
                            {--business-account-id= : WhatsApp Business Account ID}
                            {--token= : Access token for this phone; pass the flag alone to be prompted, omit it to inherit WHATSAPP_ACCESS_TOKEN}
                            {--handler= : Class implementing MessageHandlerInterface}
                            {--flow-handler= : Default class implementing FlowHandlerInterface for flows sent from this phone}
                            {--batch-window= : Seconds to wait before processing a batch}
                            {--display-name= : Name shown for this number, e.g. in an inbox}
                            {--transcription : Transcribe inbound audio}
                            {--no-transcription : Do not transcribe inbound audio}
                            {--auto-typing : Send a typing indicator and read receipt on every inbound message}
                            {--no-auto-typing : Do not send the automatic typing indicator}
                            {--auto-download-media : Download inbound media automatically}
                            {--no-auto-download-media : Do not download inbound media automatically}
                            {--processing-mode= : batch or immediate}
                            {--batch-max-messages= : Max messages per batch}
                            {--inactive : Register the phone as inactive}';

    protected $description = 'Register a WhatsApp phone number';

    public function handle(): int
    {
        $key = $this->argument('key');

        if (WhatsAppPhone::withTrashed()->where('key', $key)->exists()) {
            $this->error("A phone with key '{$key}' already exists. Use whatsapp:phone:update to change it.");

            return self::FAILURE;
        }

        foreach (['phone-id', 'phone-number', 'business-account-id'] as $required) {
            if ($this->option($required) === null) {
                $this->error("The --{$required} option is required.");

                return self::FAILURE;
            }
        }

        $attributes = $this->attributesFromOptions();

        if ($attributes === null) {
            return self::FAILURE;
        }

        $phone = WhatsAppPhone::create($attributes + [
            'key' => $key,
            'is_active' => ! $this->option('inactive'),
        ]);

        $this->components->info("Phone '{$key}' registered for {$phone->phone_number}.");

        return self::SUCCESS;
    }
}
