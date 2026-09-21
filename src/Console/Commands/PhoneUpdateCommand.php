<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

class PhoneUpdateCommand extends PhoneCommand
{
    protected $signature = 'whatsapp:phone:update
                            {key : Key of the phone to change}
                            {--phone-id= : Meta phone number ID}
                            {--phone-number= : Number in E.164 format, e.g. +5511999999999}
                            {--business-account-id= : WhatsApp Business Account ID}
                            {--token= : New access token; pass the flag alone to be prompted}
                            {--handler= : Class implementing MessageHandlerInterface}
                            {--batch-window= : Seconds to wait before processing a batch}
                            {--active : Activate the phone}
                            {--inactive : Deactivate the phone}';

    protected $description = 'Change a registered WhatsApp phone; only the options given are updated';

    public function handle(): int
    {
        $key = $this->argument('key');
        $phone = WhatsAppPhone::where('key', $key)->first();

        if ($phone === null) {
            $this->error("No phone found with key '{$key}'.");

            return self::FAILURE;
        }

        $attributes = $this->attributesFromOptions();

        if ($attributes === null) {
            return self::FAILURE;
        }

        if ($this->option('inactive')) {
            $attributes['is_active'] = false;
        }

        if ($this->option('active')) {
            $attributes['is_active'] = true;
        }

        if ($attributes === []) {
            $this->error('Nothing to update: pass at least one option.');

            return self::FAILURE;
        }

        $phone->update($attributes);

        $this->components->info("Phone '{$key}' updated.");

        return self::SUCCESS;
    }
}
