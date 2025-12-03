<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalize all phone numbers to E.164 format (+XXXXXXXXXXX).
     *
     * This migration adds the '+' prefix to phone numbers that are missing it,
     * ensuring consistent phone number format across all tables.
     */
    public function up(): void
    {
        // Normalize whatsapp_phones.phone_number
        DB::table('whatsapp_phones')
            ->whereRaw("phone_number NOT LIKE '+%'")
            ->whereRaw("phone_number REGEXP '^[0-9]+$'")
            ->update([
                'phone_number' => DB::raw("CONCAT('+', phone_number)"),
            ]);

        // Normalize whatsapp_conversations.contact_phone
        DB::table('whatsapp_conversations')
            ->whereRaw("contact_phone NOT LIKE '+%'")
            ->whereRaw("contact_phone REGEXP '^[0-9]+$'")
            ->update([
                'contact_phone' => DB::raw("CONCAT('+', contact_phone)"),
            ]);

        // Normalize whatsapp_messages.from
        DB::table('whatsapp_messages')
            ->whereRaw("`from` NOT LIKE '+%'")
            ->whereRaw("`from` REGEXP '^[0-9]+$'")
            ->update([
                'from' => DB::raw("CONCAT('+', `from`)"),
            ]);

        // Normalize whatsapp_messages.to
        DB::table('whatsapp_messages')
            ->whereRaw("`to` NOT LIKE '+%'")
            ->whereRaw("`to` REGEXP '^[0-9]+$'")
            ->update([
                'to' => DB::raw("CONCAT('+', `to`)"),
            ]);
    }

    /**
     * Remove the '+' prefix from phone numbers.
     */
    public function down(): void
    {
        // Remove '+' from whatsapp_phones.phone_number
        DB::table('whatsapp_phones')
            ->whereRaw("phone_number LIKE '+%'")
            ->update([
                'phone_number' => DB::raw("SUBSTRING(phone_number, 2)"),
            ]);

        // Remove '+' from whatsapp_conversations.contact_phone
        DB::table('whatsapp_conversations')
            ->whereRaw("contact_phone LIKE '+%'")
            ->update([
                'contact_phone' => DB::raw("SUBSTRING(contact_phone, 2)"),
            ]);

        // Remove '+' from whatsapp_messages.from
        DB::table('whatsapp_messages')
            ->whereRaw("`from` LIKE '+%'")
            ->update([
                'from' => DB::raw("SUBSTRING(`from`, 2)"),
            ]);

        // Remove '+' from whatsapp_messages.to
        DB::table('whatsapp_messages')
            ->whereRaw("`to` LIKE '+%'")
            ->update([
                'to' => DB::raw("SUBSTRING(`to`, 2)"),
            ]);
    }
};
