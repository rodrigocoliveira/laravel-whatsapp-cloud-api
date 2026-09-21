<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Illuminate\Console\Command;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

class PhoneListCommand extends Command
{
    protected $signature = 'whatsapp:phone:list';

    protected $description = 'List the registered WhatsApp phones (tokens are never shown)';

    public function handle(): int
    {
        $phones = WhatsAppPhone::orderBy('key')->get();

        if ($phones->isEmpty()) {
            $this->components->info('No phones registered. Add one with whatsapp:phone:add.');

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Number', 'Phone ID', 'Handler', 'Token', 'Active'],
            $phones->map(fn (WhatsAppPhone $phone) => [
                $phone->key,
                $phone->phone_number,
                $phone->phone_id,
                $phone->handler ?? '-',
                $phone->getRawOriginal('access_token') !== null ? 'own' : 'app-wide',
                $phone->is_active ? 'yes' : 'no',
            ])->all()
        );

        return self::SUCCESS;
    }
}
