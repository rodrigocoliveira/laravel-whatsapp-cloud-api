<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_batches', function (Blueprint $table) {
            // Composite index for chronological batch ordering queries
            // Used by WhatsAppProcessBatch to check for older pending batches
            // and to find the next batch to process
            $table->index(
                ['whatsapp_conversation_id', 'status', 'id'],
                'batches_conversation_status_id_idx'
            );
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // Index for batch message queries by status
            // Used when fetching ready messages for processing
            $table->index(
                ['whatsapp_message_batch_id', 'status'],
                'messages_batch_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_batches', function (Blueprint $table) {
            $table->dropIndex('batches_conversation_status_id_idx');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('messages_batch_status_idx');
        });
    }
};
