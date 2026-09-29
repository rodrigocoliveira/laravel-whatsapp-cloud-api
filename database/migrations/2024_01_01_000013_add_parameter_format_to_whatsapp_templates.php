<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meta reports NAMED or POSITIONAL per template; existing rows stay null until the next sync.
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->string('parameter_format')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropColumn('parameter_format');
        });
    }
};
