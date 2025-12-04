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
            $table->index(['whatsapp_conversation_id', 'status'], 'batches_conversation_status_idx');
        });

        Schema::table('whatsapp_phones', function (Blueprint $table) {
            $table->index(['phone_id', 'is_active'], 'phones_phone_id_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_batches', function (Blueprint $table) {
            $table->dropIndex('batches_conversation_status_idx');
        });

        Schema::table('whatsapp_phones', function (Blueprint $table) {
            $table->dropIndex('phones_phone_id_active_idx');
        });
    }
};
