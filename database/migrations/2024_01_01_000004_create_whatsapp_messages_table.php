<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_phone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_conversation_id')->nullable()->constrained();
            $table->foreignId('whatsapp_message_batch_id')->nullable()->constrained();
            $table->string('message_id')->unique();
            $table->string('direction');
            $table->string('type');
            $table->string('from');
            $table->string('to');
            $table->json('content')->nullable();
            $table->text('text_body')->nullable();

            // Processing status
            $table->string('status')->default('received');
            $table->string('filtered_reason')->nullable();

            // Media processing
            $table->string('media_id')->nullable();
            $table->string('media_status')->nullable();
            $table->string('local_media_path')->nullable();
            $table->string('local_media_disk')->nullable();
            $table->string('media_mime_type')->nullable();
            $table->integer('media_size')->nullable();

            // Audio transcription
            $table->string('transcription_status')->nullable();
            $table->text('transcription')->nullable();
            $table->string('transcription_language')->nullable();
            $table->decimal('transcription_duration', 10, 2)->nullable();

            // Outbound delivery status
            $table->string('delivery_status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // Template info (for outbound)
            $table->string('template_name')->nullable();
            $table->json('template_parameters')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['from', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('whatsapp_message_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
