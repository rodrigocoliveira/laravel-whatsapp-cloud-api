<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Services\Transcription;

use Multek\LaravelWhatsAppCloud\Contracts\TranscriptionServiceInterface;
use Multek\LaravelWhatsAppCloud\DTOs\TranscriptionResult;
use Multek\LaravelWhatsAppCloud\Exceptions\TranscriptionException;
use OpenAI;
use OpenAI\Client;

class OpenAITranscriber implements TranscriptionServiceInterface
{
    protected const SUPPORTED_MIME_TYPES = [
        'audio/aac',
        'audio/mp4',
        'audio/m4a',
        'audio/mpeg',
        'audio/mp3',
        'audio/ogg',
        'audio/opus',
        'audio/webm',
        'audio/wav',
        'audio/x-wav',
    ];

    protected ?Client $client = null;

    /**
     * Transcribe an audio file to text using OpenAI Whisper.
     *
     * Language is auto-detected by the Whisper model.
     *
     * @throws TranscriptionException
     */
    public function transcribe(string $audioPath): TranscriptionResult
    {
        if (! file_exists($audioPath)) {
            throw TranscriptionException::fileNotFound($audioPath);
        }

        $client = $this->getClient();
        $model = config('whatsapp.transcription.services.openai.model', 'whisper-1');

        try {
            $response = $client->audio()->transcribe([
                'model' => $model,
                'file' => fopen($audioPath, 'r'),
                'response_format' => 'verbose_json',
            ]);

            return new TranscriptionResult(
                text: trim($response->text),
                detectedLanguage: $response->language ?? null,
                duration: $response->duration ?? null,
            );

        } catch (TranscriptionException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw TranscriptionException::transcriptionFailed($e->getMessage());
        }
    }

    /**
     * Check if a mime type is supported for transcription.
     */
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    /**
     * Get the OpenAI client instance.
     *
     * @throws TranscriptionException
     */
    protected function getClient(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (! class_exists(OpenAI::class)) {
            throw TranscriptionException::serviceNotConfigured(
                'openai - The openai-php/client package is required. Run: composer require openai-php/client'
            );
        }

        $apiKey = config('whatsapp.transcription.services.openai.api_key');

        if (! $apiKey) {
            throw TranscriptionException::serviceNotConfigured('openai');
        }

        $this->client = OpenAI::client($apiKey);

        return $this->client;
    }

    /**
     * Set a custom client (useful for testing).
     */
    public function setClient(Client $client): void
    {
        $this->client = $client;
    }
}
