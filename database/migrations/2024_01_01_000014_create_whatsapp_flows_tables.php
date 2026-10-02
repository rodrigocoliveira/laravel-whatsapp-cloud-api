<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A local mirror of the flows on Meta, synced per phone like templates.
        Schema::create('whatsapp_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_phone_id')->constrained()->cascadeOnDelete();
            $table->string('flow_id');
            $table->string('name');
            $table->string('status');
            $table->json('categories')->nullable();
            $table->json('validation_errors')->nullable();
            // Set by the app, never touched by the sync.
            $table->string('handler')->nullable();
            $table->boolean('single_use')->nullable();
            $table->unsignedInteger('session_ttl_hours')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_phone_id', 'flow_id']);
            $table->index(['whatsapp_phone_id', 'name']);
        });

        // One row per flow message sent: what its flow_token means.
        Schema::create('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('flow_token')->unique();
            $table->foreignId('whatsapp_flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('response_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->string('status');
            $table->json('state')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flow_sessions');
        Schema::dropIfExists('whatsapp_flows');
    }
};
