<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta's access tokens exceed 255 characters, so the original string column
     * truncated them and made phones unusable.
     */
    public function up(): void
    {
        Schema::table('whatsapp_phones', function (Blueprint $table) {
            $table->text('access_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_phones', function (Blueprint $table) {
            $table->string('access_token')->nullable()->change();
        });
    }
};
