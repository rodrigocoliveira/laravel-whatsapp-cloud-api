<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Illuminate\Console\Command;
use Multek\LaravelWhatsAppCloud\Contracts\MessageHandlerInterface;
use Multek\LaravelWhatsAppCloud\Support\PhoneNumberHelper;

abstract class PhoneCommand extends Command
{
    /**
     * Phone attributes for the options that were given, or null when one of them is invalid.
     *
     * @return array<string, mixed>|null
     */
    protected function attributesFromOptions(): ?array
    {
        $attributes = [];

        foreach (['phone-id' => 'phone_id', 'business-account-id' => 'business_account_id'] as $option => $column) {
            if ($this->option($option) !== null) {
                $attributes[$column] = $this->option($option);
            }
        }

        if (($number = $this->option('phone-number')) !== null) {
            $normalized = PhoneNumberHelper::normalize($number);

            if (! preg_match('/^\+[1-9]\d{6,14}$/', $normalized)) {
                $this->error("'{$number}' is not a valid E.164 phone number (e.g. +5511999999999).");

                return null;
            }

            $attributes['phone_number'] = $normalized;
        }

        if (($handler = $this->option('handler')) !== null) {
            if (! class_exists($handler) || ! is_subclass_of($handler, MessageHandlerInterface::class)) {
                $this->error("Handler {$handler} must exist and implement ".MessageHandlerInterface::class.'.');

                return null;
            }

            $attributes['handler'] = $handler;
        }

        if (($window = $this->option('batch-window')) !== null) {
            $attributes['batch_window_seconds'] = (int) $window;
        }

        if ($this->input->hasParameterOption('--token')) {
            $attributes['access_token'] = $this->resolveToken();
        }

        return $attributes;
    }

    /**
     * A token given inline lands in the shell history, so `--token` alone prompts for it instead.
     */
    protected function resolveToken(): string
    {
        $token = $this->option('token');

        if ($token !== null) {
            $this->components->warn('Tokens passed inline stay in your shell history; pass --token without a value to be prompted.');

            return $token;
        }

        return (string) $this->secret('Access token');
    }
}
