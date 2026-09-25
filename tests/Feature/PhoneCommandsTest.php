<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Contracts\MessageHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\IncomingMessageContext;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

function addPhoneOptions(array $overrides = []): array
{
    return array_merge([
        'key' => 'support',
        '--phone-id' => '10987654321',
        '--phone-number' => '55 11 99999-9999',
        '--business-account-id' => 'waba_123',
    ], $overrides);
}

describe('whatsapp:phone:add', function () {
    it('registers a phone that inherits the app-wide token', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions([
            '--handler' => PhoneCommandsTestHandler::class,
            '--batch-window' => '5',
        ]))->assertSuccessful();

        $phone = WhatsAppPhone::where('key', 'support')->first();

        expect($phone)->not->toBeNull()
            ->and($phone->phone_id)->toBe('10987654321')
            ->and($phone->phone_number)->toBe('+5511999999999')
            ->and($phone->business_account_id)->toBe('waba_123')
            ->and($phone->handler)->toBe(PhoneCommandsTestHandler::class)
            ->and($phone->batch_window_seconds)->toBe(5)
            ->and($phone->is_active)->toBeTrue()
            ->and($phone->getRawOriginal('access_token'))->toBeNull();
    });

    it('prompts for the token when --token is passed without a value', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--token' => null]))
            ->expectsQuestion('Access token', 'EAAB.rotated')
            ->assertSuccessful();

        expect(WhatsAppPhone::where('key', 'support')->first()->access_token)->toBe('EAAB.rotated');
    });

    it('registers an inactive phone with --inactive', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--inactive' => true]))->assertSuccessful();

        expect(WhatsAppPhone::where('key', 'support')->first()->is_active)->toBeFalse();
    });

    it('refuses to overwrite an existing key', function () {
        WhatsAppPhone::create([
            'key' => 'support',
            'phone_id' => 'existing',
            'phone_number' => '+15551234567',
            'business_account_id' => 'waba_existing',
        ]);

        $this->artisan('whatsapp:phone:add', addPhoneOptions())
            ->expectsOutputToContain('whatsapp:phone:update')
            ->assertFailed();

        expect(WhatsAppPhone::where('key', 'support')->first()->phone_id)->toBe('existing');
    });

    it('rejects a handler that does not implement MessageHandlerInterface', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--handler' => stdClass::class]))
            ->expectsOutputToContain(MessageHandlerInterface::class)
            ->assertFailed();

        expect(WhatsAppPhone::count())->toBe(0);
    });

    it('rejects a handler class that does not exist', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--handler' => 'App\\Missing\\Handler']))
            ->assertFailed();

        expect(WhatsAppPhone::count())->toBe(0);
    });

    it('rejects a phone number that is not E.164', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--phone-number' => 'not-a-number']))
            ->assertFailed();

        expect(WhatsAppPhone::count())->toBe(0);
    });

    it('requires the phone id, the number and the business account id', function (string $missing) {
        $options = addPhoneOptions();
        unset($options[$missing]);

        $this->artisan('whatsapp:phone:add', $options)
            ->expectsOutputToContain($missing)
            ->assertFailed();

        expect(WhatsAppPhone::count())->toBe(0);
    })->with(['--phone-id', '--phone-number', '--business-account-id']);
});

describe('whatsapp:phone:update', function () {
    beforeEach(function () {
        $this->phone = WhatsAppPhone::create([
            'key' => 'support',
            'phone_id' => '10987654321',
            'phone_number' => '+5511999999999',
            'business_account_id' => 'waba_123',
            'handler' => PhoneCommandsTestHandler::class,
            'batch_window_seconds' => 3,
            'access_token' => 'EAAB.old',
        ]);
    });

    it('changes only the options that were given', function () {
        $this->artisan('whatsapp:phone:update', ['key' => 'support', '--batch-window' => '8'])->assertSuccessful();

        $phone = $this->phone->fresh();

        expect($phone->batch_window_seconds)->toBe(8)
            ->and($phone->phone_id)->toBe('10987654321')
            ->and($phone->handler)->toBe(PhoneCommandsTestHandler::class)
            ->and($phone->access_token)->toBe('EAAB.old')
            ->and($phone->is_active)->toBeTrue();
    });

    it('rotates the token through a hidden prompt', function () {
        $this->artisan('whatsapp:phone:update', ['key' => 'support', '--token' => null])
            ->expectsQuestion('Access token', 'EAAB.new')
            ->assertSuccessful();

        expect($this->phone->fresh()->access_token)->toBe('EAAB.new');
    });

    it('toggles activation with --inactive and --active', function () {
        $this->artisan('whatsapp:phone:update', ['key' => 'support', '--inactive' => true])->assertSuccessful();
        expect($this->phone->fresh()->is_active)->toBeFalse();

        $this->artisan('whatsapp:phone:update', ['key' => 'support', '--active' => true])->assertSuccessful();
        expect($this->phone->fresh()->is_active)->toBeTrue();
    });

    it('fails for an unknown key', function () {
        $this->artisan('whatsapp:phone:update', ['key' => 'nope', '--batch-window' => '8'])->assertFailed();
    });

    it('fails when no option is given', function () {
        $this->artisan('whatsapp:phone:update', ['key' => 'support'])->assertFailed();
    });

    it('validates the handler like add does', function () {
        $this->artisan('whatsapp:phone:update', ['key' => 'support', '--handler' => stdClass::class])->assertFailed();

        expect($this->phone->fresh()->handler)->toBe(PhoneCommandsTestHandler::class);
    });
});

describe('whatsapp:phone:list', function () {
    it('lists phones without ever printing a token', function () {
        WhatsAppPhone::create([
            'key' => 'support',
            'phone_id' => '10987654321',
            'phone_number' => '+5511999999999',
            'business_account_id' => 'waba_123',
            'handler' => PhoneCommandsTestHandler::class,
            'access_token' => 'EAAB.secret',
        ]);
        WhatsAppPhone::create([
            'key' => 'negotiator',
            'phone_id' => '20987654321',
            'phone_number' => '+5511888888888',
            'business_account_id' => 'waba_123',
            'is_active' => false,
        ]);

        $this->artisan('whatsapp:phone:list')
            ->expectsTable(
                ['Key', 'Number', 'Phone ID', 'Handler', 'Token', 'Active'],
                [
                    ['negotiator', '+5511888888888', '20987654321', '-', 'app-wide', 'no'],
                    ['support', '+5511999999999', '10987654321', PhoneCommandsTestHandler::class, 'own', 'yes'],
                ]
            )
            ->doesntExpectOutputToContain('EAAB.secret')
            ->assertSuccessful();
    });

    it('says so when there are no phones', function () {
        $this->artisan('whatsapp:phone:list')
            ->expectsOutputToContain('No phones registered')
            ->assertSuccessful();
    });
});

class PhoneCommandsTestHandler implements MessageHandlerInterface
{
    public function handle(IncomingMessageContext $context): void {}
}
