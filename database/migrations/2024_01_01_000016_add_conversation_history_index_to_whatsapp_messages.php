<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'messages_conversation_created_id_idx';

    /**
     * PostgreSQL cannot build an index concurrently inside a transaction.
     *
     * @var bool
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // A failed concurrent build leaves an INVALID index that would block the retry
        if (Schema::hasIndex('whatsapp_messages', self::INDEX)) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // Composite index for paginating a conversation's history by (created_at, id).
            // Runs on every consumer's next migrate, with webhooks writing to this table,
            // so it is built without blocking writes on PostgreSQL
            $table->index(
                ['whatsapp_conversation_id', 'created_at', 'id'],
                self::INDEX
            )->online();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // MySQL drops the foreign key's implicit index once this one can serve it,
            // then refuses to drop this one unless another index backs the foreign key
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                && ! Schema::hasIndex('whatsapp_messages', ['whatsapp_conversation_id'])) {
                $table->index('whatsapp_conversation_id');
            }

            $table->dropIndex(self::INDEX);
        });
    }
};
