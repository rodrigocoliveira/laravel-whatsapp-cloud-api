<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\AudioContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\ContactsContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\DocumentContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\ImageContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\InteractiveReplyContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\LocationContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\ReactionContent;
use Multek\LaravelWhatsAppCloud\DTOs\MessageContent\TextContent;

it('creates text content with body', function () {
    $content = new TextContent('Hello World');

    expect($content->body)->toBe('Hello World');
    expect($content->getType())->toBe('text');
    expect($content->toArray())->toBe(['body' => 'Hello World']);
});

it('creates image content with all fields', function () {
    $content = new ImageContent(
        mediaId: 'media123',
        caption: 'Test caption',
        mimeType: 'image/jpeg',
        sha256: 'abc123'
    );

    expect($content->mediaId)->toBe('media123');
    expect($content->caption)->toBe('Test caption');
    expect($content->mimeType)->toBe('image/jpeg');
    expect($content->getType())->toBe('image');
});

it('creates audio content and detects voice notes', function () {
    $voiceNote = new AudioContent(mediaId: 'audio123', voice: true);
    $regularAudio = new AudioContent(mediaId: 'audio456', voice: false);

    expect($voiceNote->isVoiceNote())->toBeTrue();
    expect($regularAudio->isVoiceNote())->toBeFalse();
});

it('creates location content with coordinates', function () {
    $content = new LocationContent(
        latitude: -23.55,
        longitude: -46.63,
        name: 'Office',
        address: 'Av. Paulista, 1000'
    );

    expect($content->latitude)->toBe(-23.55);
    expect($content->longitude)->toBe(-46.63);
    expect($content->name)->toBe('Office');
    expect($content->getType())->toBe('location');
});

it('creates contacts content with multiple contacts', function () {
    $contacts = [
        ['name' => ['formatted_name' => 'John Doe']],
        ['name' => ['formatted_name' => 'Jane Doe']],
    ];

    $content = new ContactsContent($contacts);

    expect($content->getCount())->toBe(2);
    expect($content->getContacts())->toBe($contacts);
});

it('creates interactive reply content and identifies type', function () {
    $buttonReply = new InteractiveReplyContent(
        replyType: 'button_reply',
        id: 'btn_confirm',
        title: 'Confirm'
    );

    $listReply = new InteractiveReplyContent(
        replyType: 'list_reply',
        id: 'option_1',
        title: 'Option 1',
        description: 'First option'
    );

    expect($buttonReply->isButtonReply())->toBeTrue();
    expect($buttonReply->isListReply())->toBeFalse();
    expect($listReply->isListReply())->toBeTrue();
    expect($listReply->description)->toBe('First option');
});

it('creates reaction content and detects removal', function () {
    $reaction = new ReactionContent(messageId: 'msg123', emoji: '👍');
    $removal = new ReactionContent(messageId: 'msg123', emoji: '');

    expect($reaction->isRemoval())->toBeFalse();
    expect($removal->isRemoval())->toBeTrue();
});

it('creates document content with filename', function () {
    $content = new DocumentContent(
        mediaId: 'doc123',
        filename: 'report.pdf',
        caption: 'Monthly report',
        mimeType: 'application/pdf'
    );

    expect($content->filename)->toBe('report.pdf');
    expect($content->caption)->toBe('Monthly report');
    expect($content->getType())->toBe('document');
});
