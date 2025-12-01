<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_phone_id')->constrained()->cascadeOnDelete();
            $table->string('template_id');
            $table->string('name');
            $table->string('language')->default('pt_BR');
            $table->string('category');
            $table->string('status');
            $table->json('components');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_phone_id', 'name', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
