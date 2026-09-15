<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->timestamp('last_inbound_message_at')->nullable()->after('last_message_at');
        });

        // Backfill from last_message_at so existing conversations don't read as
        // outside the window until their next inbound message arrives.
        DB::table('whatsapp_conversations')->update([
            'last_inbound_message_at' => DB::raw('last_message_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn('last_inbound_message_at');
        });
    }
};
