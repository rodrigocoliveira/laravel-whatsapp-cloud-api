<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSyncFlows;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

class SyncFlowsCommand extends SyncTemplatesCommand
{
    protected $signature = 'whatsapp:sync-flows
                            {--phone= : Sync flows for a specific phone key}
                            {--queue : Queue the sync jobs instead of running immediately}';

    protected $description = 'Sync WhatsApp Flows from Meta for WhatsApp phones';

    protected string $resource = 'flow';

    protected function job(WhatsAppPhone $phone): WhatsAppSyncFlows
    {
        return new WhatsAppSyncFlows($phone);
    }
}
