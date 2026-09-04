<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessageBatch;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppTemplate;

beforeEach(function () {
    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->conversation = WhatsAppConversation::create([
        'whatsapp_phone_id' => $this->phone->id,
        'contact_phone' => '+5511999999999',
        'last_message_at' => now(),
        'status' => WhatsAppConversation::STATUS_ACTIVE,
    ]);

    $this->batch = WhatsAppMessageBatch::create([
        'whatsapp_phone_id' => $this->phone->id,
        'whatsapp_conversation_id' => $this->conversation->id,
        'status' => 'collecting',
        'first_message_at' => now(),
        'process_after' => now(),
    ]);

    $this->message = WhatsAppMessage::create([
        'whatsapp_phone_id' => $this->phone->id,
        'whatsapp_conversation_id' => $this->conversation->id,
        'whatsapp_message_batch_id' => $this->batch->id,
        'message_id' => 'wamid.relations',
        'direction' => 'inbound',
        'type' => 'text',
        'from' => '+5511999999999',
        'to' => '+15551234567',
        'content' => ['body' => 'hi'],
        'text_body' => 'hi',
        'status' => 'received',
    ]);

    $this->template = WhatsAppTemplate::create([
        'whatsapp_phone_id' => $this->phone->id,
        'template_id' => 'tpl_1',
        'name' => 'hello_world',
        'language' => 'en_US',
        'status' => 'APPROVED',
        'category' => 'UTILITY',
        'components' => [],
    ]);
});

it('resolves conversations through whatsapp_phone_id on the phone', function () {
    expect($this->phone->conversations()->pluck('id')->all())->toBe([$this->conversation->id]);
});

it('resolves messages through whatsapp_phone_id on the phone', function () {
    expect($this->phone->messages()->pluck('id')->all())->toBe([$this->message->id]);
});

it('resolves batches through whatsapp_phone_id on the phone', function () {
    expect($this->phone->batches()->pluck('id')->all())->toBe([$this->batch->id]);
});

it('resolves templates through whatsapp_phone_id on the phone', function () {
    expect($this->phone->templates()->pluck('id')->all())->toBe([$this->template->id]);
});

it('resolves messages through whatsapp_conversation_id on the conversation', function () {
    expect($this->conversation->messages()->pluck('id')->all())->toBe([$this->message->id]);
});

it('resolves batches through whatsapp_conversation_id on the conversation', function () {
    expect($this->conversation->batches()->pluck('id')->all())->toBe([$this->batch->id]);
});
