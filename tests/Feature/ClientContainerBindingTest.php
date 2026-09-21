<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

it('refuses to resolve WhatsAppClientInterface from the container, which has no phone to bind it to', function () {
    expect(fn () => app(WhatsAppClientInterface::class))
        ->toThrow(BindingResolutionException::class, 'bound to a phone');
});

it('refuses to inject WhatsAppClientInterface into a method, the way a job handle() would ask for it', function () {
    expect(fn () => app()->call(fn (WhatsAppClientInterface $client) => $client))
        ->toThrow(BindingResolutionException::class, 'bound to a phone');
});

it('still hands out a client bound to the selected phone through the manager', function () {
    WhatsAppPhone::create([
        'key' => 'support',
        'phone_id' => 'support_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $client = app(WhatsAppManager::class)->phone('support')->getClient();

    expect($client)->toBeInstanceOf(WhatsAppClient::class);
});
