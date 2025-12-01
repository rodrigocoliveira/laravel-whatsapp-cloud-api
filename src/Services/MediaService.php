<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Contracts\MediaStorageInterface;
use Multek\LaravelWhatsAppCloud\Exceptions\MediaDownloadException;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;

class MediaService implements MediaStorageInterface
{
    public function __construct(
        protected WhatsAppClientInterface $client,
    ) {}

    /**
     * Download media from WhatsApp and store it locally.
     *
     * @throws MediaDownloadException
     */
    public function download(WhatsAppMessage $message): string
    {
        if (! $message->media_id) {
            throw MediaDownloadException::mediaNotFound('null');
        }

        try {
            // Get the media URL from WhatsApp API
            $mediaUrl = $this->client->getMediaUrl($message->media_id);

            // Download the media content
            $content = $this->client->downloadMedia($message->media_id);

            // Check file size
            $maxSize = config('whatsapp.media.max_size', 16 * 1024 * 1024);
            if (strlen($content) > $maxSize) {
                throw MediaDownloadException::fileTooLarge(strlen($content), $maxSize);
            }

            // Determine storage path
            $disk = config('whatsapp.media.storage_disk', 'local');
            $basePath = config('whatsapp.media.storage_path', 'whatsapp/media');
            $extension = $this->getExtensionFromMimeType($message->media_mime_type);
            $filename = Str::uuid()->toString().'.'.$extension;
            $path = $basePath.'/'.date('Y/m/d').'/'.$filename;

            // Store the file
            Storage::disk($disk)->put($path, $content);

            // Update message with media info
            $message->update([
                'local_media_path' => $path,
                'local_media_disk' => $disk,
                'media_size' => strlen($content),
            ]);

            return $path;

        } catch (MediaDownloadException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw MediaDownloadException::downloadFailed(
                $message->media_id,
                $e->getMessage()
            );
        }
    }

    /**
     * Get the signed URL for a stored media file.
     */
    public function getUrl(WhatsAppMessage $message): ?string
    {
        if (! $message->local_media_path || ! $message->local_media_disk) {
            return null;
        }

        $disk = Storage::disk($message->local_media_disk);

        // If disk supports temporary URLs (like S3), use them
        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($message->local_media_path, now()->addHour());
            } catch (\Exception $e) {
                // Fall back to regular URL
            }
        }

        return $disk->url($message->local_media_path);
    }

    /**
     * Delete a stored media file.
     */
    public function delete(WhatsAppMessage $message): bool
    {
        if (! $message->local_media_path || ! $message->local_media_disk) {
            return false;
        }

        $deleted = Storage::disk($message->local_media_disk)->delete($message->local_media_path);

        if ($deleted) {
            $message->update([
                'local_media_path' => null,
                'local_media_disk' => null,
            ]);
        }

        return $deleted;
    }

    /**
     * Get file extension from MIME type.
     */
    protected function getExtensionFromMimeType(?string $mimeType): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/aac' => 'aac',
            'audio/mp4' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/amr' => 'amr',
            'audio/ogg' => 'ogg',
            'audio/opus' => 'opus',
            'application/pdf' => 'pdf',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
        ];

        return $mimeMap[$mimeType] ?? 'bin';
    }
}
