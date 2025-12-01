<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Services\Transcription;

use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Contracts\TranscriptionServiceInterface;
use Multek\LaravelWhatsAppCloud\Exceptions\TranscriptionException;

class OpenAITranscriber implements TranscriptionServiceInterface
{
    protected const API_URL = 'https://api.openai.com/v1/audio/transcriptions';

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

    /**
     * Transcribe an audio file to text using OpenAI Whisper.
     *
     * @throws TranscriptionException
     */
    public function transcribe(string $audioPath, string $language = 'pt-BR'): string
    {
        $apiKey = config('whatsapp.transcription.services.openai.api_key');
        $model = config('whatsapp.transcription.services.openai.model', 'whisper-1');

        if (! $apiKey) {
            throw TranscriptionException::serviceNotConfigured('openai');
        }

        if (! file_exists($audioPath)) {
            throw TranscriptionException::fileNotFound($audioPath);
        }

        // Convert language code to ISO-639-1 format (e.g., pt-BR -> pt)
        $languageCode = explode('-', $language)[0];

        try {
            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->attach(
                    'file',
                    file_get_contents($audioPath),
                    basename($audioPath)
                )
                ->post(self::API_URL, [
                    'model' => $model,
                    'language' => $languageCode,
                    'response_format' => 'text',
                ]);

            if (! $response->successful()) {
                $error = $response->json('error.message', $response->body());
                throw TranscriptionException::transcriptionFailed($error);
            }

            return trim($response->body());

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
}
