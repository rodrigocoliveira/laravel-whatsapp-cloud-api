<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_phones', function (Blueprint $table) {
            // Maximum total window time for a batch (prevents infinite extension)
            // Default 30 seconds - batch will process at most 30s after first message
            $table->unsignedInteger('batch_max_window_seconds')
                ->default(30)
                ->after('batch_max_messages');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_phones', function (Blueprint $table) {
            $table->dropColumn('batch_max_window_seconds');
        });
    }
};
