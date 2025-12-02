<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_phones', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('phone_id');
            $table->string('phone_number');
            $table->string('display_name')->nullable();
            $table->string('business_account_id');
            $table->string('access_token')->nullable();

            // Handler configuration
            $table->string('handler')->nullable();
            $table->json('handler_config')->nullable();

            // Batch processing
            $table->string('processing_mode')->default('batch');
            $table->integer('batch_window_seconds')->default(3);
            $table->integer('batch_max_messages')->default(10);

            // Media handling
            $table->boolean('auto_download_media')->default(true);
            $table->boolean('transcription_enabled')->default(false);
            $table->string('transcription_service')->nullable();

            // Message type filtering
            $table->json('allowed_message_types')->nullable();
            $table->string('on_disallowed_type')->default('ignore');
            $table->string('disallowed_type_reply')->nullable();

            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_phones');
    }
};
