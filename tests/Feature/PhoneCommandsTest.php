<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Contracts\FlowHandlerInterface;
use Multek\LaravelWhatsAppCloud\Contracts\MessageHandlerInterface;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowRequest;
use Multek\LaravelWhatsAppCloud\DTOs\Flows\FlowResponse;
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

    it('sets the default flow handler and validates its interface', function () {
        $this->artisan('whatsapp:phone:update', ['key' => 'support', '--flow-handler' => stdClass::class])->assertFailed();
        $this->artisan('whatsapp:phone:update', ['key' => 'support', '--flow-handler' => PhoneCommandsTestFlowHandler::class])->assertSuccessful();

        expect($this->phone->fresh()->flow_handler)->toBe(PhoneCommandsTestFlowHandler::class);
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
                ['Key', 'Number', 'Phone ID', 'Handler', 'Token', 'Active', 'Typing', 'Transcription'],
                [
                    ['negotiator', '+5511888888888', '20987654321', '-', 'app-wide', 'no', 'yes', 'no'],
                    ['support', '+5511999999999', '10987654321', PhoneCommandsTestHandler::class, 'own', 'yes', 'yes', 'no'],
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

class PhoneCommandsTestFlowHandler implements FlowHandlerInterface
{
    public function handle(FlowRequest $request): FlowResponse
    {
        return FlowResponse::screen('DONE');
    }
}

describe('phone settings flags', function () {
    it('sets display name, toggles and processing options on add', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions([
            '--display-name' => 'Fornecedores',
            '--transcription' => true,
            '--no-auto-typing' => true,
            '--no-auto-download-media' => true,
            '--processing-mode' => 'immediate',
            '--batch-max-messages' => '20',
        ]))->assertSuccessful();

        $phone = WhatsAppPhone::where('key', 'support')->first();

        expect($phone->display_name)->toBe('Fornecedores')
            ->and($phone->transcription_enabled)->toBeTrue()
            ->and($phone->auto_typing_enabled)->toBeFalse()
            ->and($phone->auto_download_media)->toBeFalse()
            ->and($phone->processing_mode)->toBe('immediate')
            ->and($phone->batch_max_messages)->toBe(20);
    });

    it('keeps model defaults on add when the flags are omitted', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions())->assertSuccessful();

        $phone = WhatsAppPhone::where('key', 'support')->first();

        expect($phone->transcription_enabled)->toBeFalse()
            ->and($phone->auto_typing_enabled)->toBeTrue()
            ->and($phone->processing_mode)->toBe('batch');
    });

    it('flips toggles both ways on update and leaves the rest alone', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--transcription' => true]))->assertSuccessful();

        $this->artisan('whatsapp:phone:update', [
            'key' => 'support',
            '--no-transcription' => true,
            '--no-auto-typing' => true,
        ])->assertSuccessful();

        $phone = WhatsAppPhone::where('key', 'support')->first();

        expect($phone->transcription_enabled)->toBeFalse()
            ->and($phone->auto_typing_enabled)->toBeFalse()
            ->and($phone->auto_download_media)->toBeTrue();

        $this->artisan('whatsapp:phone:update', ['key' => 'support', '--auto-typing' => true])->assertSuccessful();

        expect($phone->refresh()->auto_typing_enabled)->toBeTrue();
    });

    it('rejects both sides of a toggle', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--transcription' => true, '--no-transcription' => true]))
            ->assertFailed();

        expect(WhatsAppPhone::count())->toBe(0);
    });

    it('rejects an unknown processing mode', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--processing-mode' => 'later']))->assertFailed();
    });

    it('rejects a non-positive batch max', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--batch-max-messages' => '0']))->assertFailed();
    });

    it('shows typing and transcription in the list', function () {
        $this->artisan('whatsapp:phone:add', addPhoneOptions(['--no-auto-typing' => true]))->assertSuccessful();

        $this->artisan('whatsapp:phone:list')
            ->expectsTable(
                ['Key', 'Number', 'Phone ID', 'Handler', 'Token', 'Active', 'Typing', 'Transcription'],
                [['support', '+5511999999999', '10987654321', '-', 'app-wide', 'yes', 'no', 'no']],
            )
            ->assertSuccessful();
    });
});
