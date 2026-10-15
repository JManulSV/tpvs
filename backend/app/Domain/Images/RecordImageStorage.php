<?php

namespace App\Domain\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordImageStorage
{
    private const DISK = 'record_images';

    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * @var array<string, string>
     */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Validate and store an uploaded image using a server-generated storage key.
     *
     * @return array{file_path: string, original_file_name: string, mime_type: string, file_size: int}
     */
    public function store(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'image' => ['The uploaded image could not be received successfully.'],
            ]);
        }

        $fileSize = $file->getSize();
        if (! is_int($fileSize) || $fileSize < 1 || $fileSize > self::MAX_FILE_SIZE) {
            throw ValidationException::withMessages([
                'image' => ['The image must be between 1 byte and 10 MiB.'],
            ]);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $imageMetadata = @getimagesize($file->getPathname());
        $detectedImageMime = is_array($imageMetadata) ? ($imageMetadata['mime'] ?? null) : null;

        if (! is_string($mimeType) || $mimeType !== $detectedImageMime) {
            throw ValidationException::withMessages([
                'image' => ['The uploaded content is not a valid image.'],
            ]);
        }

        $extension = self::MIME_EXTENSIONS[$mimeType] ?? null;
        if ($extension === null) {
            throw ValidationException::withMessages([
                'image' => ['The image must contain JPEG, PNG, or WebP content.'],
            ]);
        }

        $originalFileName = $this->sanitizeOriginalFileName($file->getClientOriginalName());
        $storageKey = Str::uuid()->toString().'.'.$extension;

        $disk = Storage::disk(self::DISK);

        try {
            $storedPath = $disk->putFileAs('', $file, $storageKey);
        } catch (\Throwable $exception) {
            try {
                if ($disk->exists($storageKey)) {
                    $disk->delete($storageKey);
                }
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        if (! is_string($storedPath) || $storedPath === '') {
            try {
                if ($disk->exists($storageKey)) {
                    $disk->delete($storageKey);
                }
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }

            throw new \RuntimeException('The private image could not be stored.');
        }

        return [
            'file_path' => $storedPath,
            'original_file_name' => $originalFileName,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ];
    }

    private function sanitizeOriginalFileName(string $fileName): string
    {
        $baseName = basename(str_replace('\\', '/', $fileName));
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', '', $baseName) ?? '';
        $sanitized = trim($sanitized, " \t\n\r\0\x0B.");
        $sanitized = mb_substr($sanitized, 0, 255, 'UTF-8');

        if ($sanitized === '') {
            throw ValidationException::withMessages([
                'image' => ['The image must have a valid original filename.'],
            ]);
        }

        return $sanitized;
    }
}
