<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

function conversationHistoryIndexMigration(): object
{
    return require __DIR__.'/../../database/migrations/2024_01_01_000016_add_conversation_history_index_to_whatsapp_messages.php';
}

it('indexes messages by conversation, created_at and id, in that order', function () {
    $index = collect(Schema::getIndexes('whatsapp_messages'))
        ->firstWhere('name', 'messages_conversation_created_id_idx');

    expect($index['columns'])->toBe(['whatsapp_conversation_id', 'created_at', 'id']);
});

it('runs outside a transaction, which a concurrent PostgreSQL build requires', function () {
    expect(conversationHistoryIndexMigration()->withinTransaction)->toBeFalse();
});

it('drops the index on rollback', function () {
    conversationHistoryIndexMigration()->down();

    expect(Schema::hasIndex('whatsapp_messages', 'messages_conversation_created_id_idx'))->toBeFalse();
});

it('keeps an index that already exists when it runs again', function () {
    conversationHistoryIndexMigration()->up();

    expect(Schema::hasIndex('whatsapp_messages', 'messages_conversation_created_id_idx'))->toBeTrue();
});
