<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta reports billing classification (not the monetary amount) on
     * outbound status webhooks. These columns persist that classification so
     * the application can estimate and reconcile messaging costs.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->boolean('pricing_billable')->nullable()->after('read_at');
            $table->string('pricing_model', 16)->nullable()->after('pricing_billable');
            $table->string('pricing_category', 64)->nullable()->after('pricing_model');
            $table->string('pricing_type', 64)->nullable()->after('pricing_category');
            $table->string('meta_conversation_id')->nullable()->after('pricing_type');
            $table->string('conversation_origin', 64)->nullable()->after('meta_conversation_id');
            $table->timestamp('conversation_expires_at')->nullable()->after('conversation_origin');

            $table->index(['whatsapp_phone_id', 'pricing_category', 'sent_at'], 'messages_pricing_report_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('messages_pricing_report_idx');
            $table->dropColumn([
                'pricing_billable',
                'pricing_model',
                'pricing_category',
                'pricing_type',
                'meta_conversation_id',
                'conversation_origin',
                'conversation_expires_at',
            ]);
        });
    }
};
