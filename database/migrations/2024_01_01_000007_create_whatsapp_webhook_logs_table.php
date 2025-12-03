<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->json('payload');
            $table->boolean('processed')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();

            // Index for cleanup queries
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_logs');
    }
};
