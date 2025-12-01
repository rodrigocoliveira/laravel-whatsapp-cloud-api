<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppProcessBatch;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;

class ProcessStaleBatchesCommand extends Command
{
    protected $signature = 'whatsapp:process-stale-batches
                            {--dry-run : Show what would be processed without actually processing}';

    protected $description = 'Process stale/orphaned message batches that were not processed by delayed jobs';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Find batches that should have been processed (30 seconds grace period)
        $staleBatches = WhatsAppMessageBatch::query()
            ->where('status', WhatsAppMessageBatch::STATUS_COLLECTING)
            ->where('process_after', '<', Carbon::now()->subSeconds(30))
            ->get();

        if ($staleBatches->isEmpty()) {
            $this->info('No stale batches found.');

            return self::SUCCESS;
        }

        $this->info("Found {$staleBatches->count()} stale batch(es)");
        $this->newLine();

        $processed = 0;

        foreach ($staleBatches as $batch) {
            if (! $batch->shouldProcess()) {
                $this->line("  <comment>Batch #{$batch->id}</comment>: Not ready (waiting for media/transcription)");

                continue;
            }

            if ($dryRun) {
                $this->line("  <info>Batch #{$batch->id}</info>: Would be processed ({$batch->messages()->count()} messages)");
            } else {
                WhatsAppProcessBatch::dispatch($batch);
                $this->line("  <info>Batch #{$batch->id}</info>: Dispatched for processing ({$batch->messages()->count()} messages)");
            }

            $processed++;
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would process' : 'Dispatched')." {$processed} batch(es)");

        return self::SUCCESS;
    }
}
