<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_phone_id')->constrained()->cascadeOnDelete();
            $table->string('contact_phone');
            $table->string('contact_name')->nullable();
            $table->timestamp('last_message_at');
            $table->string('status')->default('active');
            $table->integer('unread_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_phone_id', 'contact_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
