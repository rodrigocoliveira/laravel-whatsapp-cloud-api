<?php

declare(strict_types=1);

use Multek\LaravelWhatsAppCloud\Exceptions\TranscriptionException;

it('formats missing transcription dependencies separately from service names', function () {
    $exception = TranscriptionException::missingDependency(
        'openai-php/client',
        'composer require openai-php/client'
    );

    expect($exception->getMessage())->toBe(
        "Transcription dependency 'openai-php/client' is not installed. Run: composer require openai-php/client"
    );
});
