<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Exceptions\MessageSendException;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->manager = app(WhatsAppManager::class)->phone('test');
});

it('caches the business profile and refreshes after an update', function () {
    $url = 'https://cdn.example/old.jpg';
    Http::fake(function (Request $request) use (&$url) {
        return $request->method() === 'GET'
            ? Http::response(['data' => [['profile_picture_url' => $url, 'about' => 'Hi']]])
            : Http::response(['success' => true]);
    });

    expect($this->manager->profile()['profile_picture_url'])->toBe('https://cdn.example/old.jpg');

    $url = 'https://cdn.example/new.jpg';
    expect($this->manager->profile()['profile_picture_url'])->toBe('https://cdn.example/old.jpg');
    Http::assertSentCount(1);

    $this->manager->updateProfile(['about' => 'Novo']);
    expect($this->manager->profile()['profile_picture_url'])->toBe('https://cdn.example/new.jpg');
    expect($this->manager->profile(fresh: true)['profile_picture_url'])->toBe('https://cdn.example/new.jpg');
    Http::assertSentCount(4);
});

it('uploads a profile picture through the resumable upload API', function () {
    config(['whatsapp.app_id' => 'app_1']);
    $file = tempnam(sys_get_temp_dir(), 'pic');
    file_put_contents($file, 'jpegbytes');

    Http::fake([
        '*/app_1/uploads*' => Http::response(['id' => 'upload:abc']),
        '*/upload:abc' => Http::response(['h' => 'HANDLE']),
        '*/whatsapp_business_profile' => Http::response(['success' => true]),
    ]);

    $this->manager->updateProfilePicture($file);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/upload:abc')
        && $r->header('Authorization')[0] === 'OAuth '.WhatsAppPhone::first()->access_token
        && $r->body() === 'jpegbytes');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/whatsapp_business_profile')
        && $r['profile_picture_handle'] === 'HANDLE');
});

it('requires an app id to upload a profile picture', function () {
    Http::fake();
    $this->manager->updateProfilePicture(__FILE__);
})->throws(MessageSendException::class, 'app_id');
