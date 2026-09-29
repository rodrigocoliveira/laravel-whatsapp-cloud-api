<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Illuminate\Console\Command;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSyncTemplates;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Throwable;

class SyncTemplatesCommand extends Command
{
    protected $signature = 'whatsapp:sync-templates
                            {--phone= : Sync templates for a specific phone key}
                            {--queue : Queue the sync jobs instead of running immediately}';

    protected $description = 'Sync message templates from Meta for WhatsApp phones';

    public function handle(): int
    {
        $phoneKey = $this->option('phone');
        $useQueue = $this->option('queue');

        $query = WhatsAppPhone::where('is_active', true);

        if ($phoneKey) {
            $query->where('key', $phoneKey);
        }

        $phones = $query->get();

        if ($phones->isEmpty()) {
            $this->error($phoneKey
                ? "No active phone found with key '{$phoneKey}'"
                : 'No active phones found'
            );

            return self::FAILURE;
        }

        $this->info('Syncing templates for '.count($phones).' phone(s)...');
        $this->newLine();

        $failed = 0;

        foreach ($phones as $phone) {
            if ($useQueue) {
                WhatsAppSyncTemplates::dispatch($phone);
                $this->line("  <comment>[{$phone->key}]</comment> Queued for sync");
            } elseif (! $this->syncPhone($phone)) {
                $failed++;
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->error("Template sync failed for {$failed} of ".count($phones).' phone(s).');

            return 1; // Command::FAILURE
        }

        $this->info('Template sync '.($useQueue ? 'queued' : 'completed').' successfully!');

        return self::SUCCESS;
    }

    protected function syncPhone(WhatsAppPhone $phone): bool
    {
        try {
            $this->components->task("Syncing [{$phone->key}] ({$phone->phone_number})", function () use ($phone) {
                $job = new WhatsAppSyncTemplates($phone);
                $job->handle();
            });

            return true;
        } catch (Throwable $e) {
            $this->components->error("[{$phone->key}] ({$phone->phone_number}): {$e->getMessage()}");

            return false;
        }
    }
}
