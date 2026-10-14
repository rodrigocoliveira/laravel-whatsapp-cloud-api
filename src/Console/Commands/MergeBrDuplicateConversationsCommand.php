<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;
use Multek\LaravelWhatsAppCloud\Support\PhoneNumberHelper;

class MergeBrDuplicateConversationsCommand extends Command
{
    protected $signature = 'whatsapp:merge-br-duplicate-conversations
                            {--dry-run : List the duplicate pairs without merging}';

    protected $description = 'Merge conversations split by the Brazilian 9th digit into the row holding the inbound wa_id';

    public function handle(): int
    {
        $pairs = [];

        // Each pair is found once, from its 13-digit (with 9) side.
        WhatsAppConversation::query()
            ->where('contact_phone', 'like', '+55%')
            ->orderBy('id')
            ->each(function (WhatsAppConversation $withNine) use (&$pairs) {
                $variants = PhoneNumberHelper::variants($withNine->contact_phone);

                if (count($variants) !== 2 || strlen($withNine->contact_phone) !== 14) {
                    return;
                }

                $withoutNine = WhatsAppConversation::where('whatsapp_phone_id', $withNine->whatsapp_phone_id)
                    ->where('contact_phone', $variants[1])
                    ->first();

                if ($withoutNine !== null) {
                    $pairs[] = $this->orderPair($withNine, $withoutNine);
                }
            });

        if ($pairs === []) {
            $this->info('No duplicate conversations found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Phone', 'Keep', 'Keep contact', 'Merge (delete)', 'Merge contact'],
            array_map(fn (array $p) => [
                $p[0]->whatsapp_phone_id, $p[0]->id, $p[0]->contact_phone, $p[1]->id, $p[1]->contact_phone,
            ], $pairs),
        );

        if ($this->option('dry-run')) {
            $this->info(count($pairs).' pair(s) would be merged (dry run).');

            return self::SUCCESS;
        }

        foreach ($pairs as [$target, $source]) {
            DB::transaction(fn () => $this->merge($target, $source));
        }

        $this->info(count($pairs).' pair(s) merged.');

        return self::SUCCESS;
    }

    /**
     * Target is the row with inbound messages (the wa_id); on a tie, the form without the 9.
     *
     * @return array{WhatsAppConversation, WhatsAppConversation}
     */
    protected function orderPair(WhatsAppConversation $withNine, WhatsAppConversation $withoutNine): array
    {
        $hasInbound = fn (WhatsAppConversation $c) => $c->messages()
            ->where('direction', WhatsAppMessage::DIRECTION_INBOUND)->exists();

        return $hasInbound($withNine) && ! $hasInbound($withoutNine)
            ? [$withNine, $withoutNine]
            : [$withoutNine, $withNine];
    }

    protected function merge(WhatsAppConversation $target, WhatsAppConversation $source): void
    {
        WhatsAppMessage::where('whatsapp_conversation_id', $source->id)
            ->update(['whatsapp_conversation_id' => $target->id]);
        WhatsAppMessageBatch::where('whatsapp_conversation_id', $source->id)
            ->update(['whatsapp_conversation_id' => $target->id]);

        $max = fn ($a, $b) => $a === null ? $b : ($b === null ? $a : $a->max($b));

        $target->update([
            'unread_count' => $target->unread_count + $source->unread_count,
            'last_message_at' => $max($target->last_message_at, $source->last_message_at),
            'last_inbound_message_at' => $max($target->last_inbound_message_at, $source->last_inbound_message_at),
            'contact_name' => $target->contact_name ?? $source->contact_name,
        ]);

        $source->delete();
    }
}
