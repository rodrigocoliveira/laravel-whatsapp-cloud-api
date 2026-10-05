<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freezes the rate-card cost at the moment Meta reports the billing
     * category, so later rate card changes do not rewrite history.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->decimal('cost', 12, 6)->nullable()->after('pricing_type');
            $table->string('cost_currency', 3)->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['cost', 'cost_currency']);
        });
    }
};
