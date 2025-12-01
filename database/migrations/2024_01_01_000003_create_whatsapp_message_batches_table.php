<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_message_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_phone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('collecting');
            $table->timestamp('first_message_at');
            $table->timestamp('process_after');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('handler_result')->nullable();
            $table->timestamps();

            $table->index(['status', 'process_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_batches');
    }
};
