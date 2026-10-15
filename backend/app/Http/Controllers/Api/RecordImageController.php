<?php

namespace App\Http\Controllers\Api;

use App\Domain\Images\RecordImageStorage;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\UploadRecordImageRequest;
use App\Models\Image;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordImageController extends Controller
{
    public function store(
        UploadRecordImageRequest $request,
        Record $record,
        RecordImageStorage $imageStorage,
    ): JsonResponse {
        if ($record->status === RecordStatus::CANCELED) {
            return $this->imageError(
                'The image cannot be changed for a canceled record.',
                'IMAGE_UPLOAD_BLOCKED',
                409,
                'RECORD_CANCELED',
            );
        }

        try {
            $stored = $imageStorage->store($request->file('image'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The image could not be stored.',
            ], 500);
        }

        $disk = Storage::disk('record_images');
        $previousPath = null;
        $wasReplacement = false;

        try {
            $result = DB::transaction(function () use ($record, $stored, &$previousPath, &$wasReplacement): Image|JsonResponse {
                $lockedRecord = Record::query()
                    ->whereKey($record->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedRecord->status === RecordStatus::CANCELED) {
                    return $this->imageError(
                        'The image cannot be changed for a canceled record.',
                        'IMAGE_UPLOAD_BLOCKED',
                        409,
                        'RECORD_CANCELED',
                    );
                }

                $image = $lockedRecord->image()->lockForUpdate()->first();
                if ($image !== null && $image->aiProcessings()->exists()) {
                    return $this->imageError(
                        'The image cannot be replaced after AI processing has started.',
                        'IMAGE_REPLACEMENT_BLOCKED',
                        409,
                        'AI_PROCESSING_EXISTS',
                    );
                }

                $wasReplacement = $image !== null;
                $previousPath = $image?->file_path;

                if ($image === null) {
                    $image = new Image;
                    $image->record()->associate($lockedRecord);
                }

                $image->fill($stored);
                $image->save();

                return $image;
            });
        } catch (\Throwable $exception) {
            $this->deleteStagedFile($stored['file_path']);
            throw $exception;
        }

        if ($result instanceof JsonResponse) {
            $this->deleteStagedFile($stored['file_path']);

            return $result;
        }

        if ($previousPath !== null && $previousPath !== $stored['file_path']) {
            try {
                $this->deleteFile($previousPath);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return response()->json([
            'data' => $this->metadata($result),
        ], $wasReplacement ? 200 : 201);
    }

    public function show(Request $request, Record $record): Response|StreamedResponse|JsonResponse
    {
        Gate::authorize('viewImage', $record);

        if ($record->status === RecordStatus::CANCELED) {
            return $this->imageError(
                'The image is unavailable for a canceled record.',
                'IMAGE_ACCESS_BLOCKED',
                409,
                'RECORD_CANCELED',
            );
        }

        $image = $record->image;
        if ($image === null || ! Storage::disk('record_images')->exists($image->file_path)) {
            abort(404);
        }

        $storedPath = Storage::disk('record_images')->path($image->file_path);
        $detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($storedPath);
        $imageMetadata = @getimagesize($storedPath);
        $detectedImageMime = is_array($imageMetadata) ? ($imageMetadata['mime'] ?? null) : null;

        if (! is_string($detectedMimeType)
            || $detectedMimeType !== $detectedImageMime
            || ! in_array($detectedMimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            abort(404);
        }

        return Storage::disk('record_images')->response(
            $image->file_path,
            $image->original_file_name,
            [
                'Content-Type' => $detectedMimeType,
                'Content-Disposition' => 'inline; filename="'.addcslashes($image->original_file_name, '"\\').'"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @return array{id: int|string, original_file_name: string, mime_type: string, file_size: int, created_at: string|null}
     */
    private function metadata(Image $image): array
    {
        return [
            'id' => $image->getKey(),
            'original_file_name' => $image->original_file_name,
            'mime_type' => $image->mime_type,
            'file_size' => (int) $image->file_size,
            'created_at' => $image->created_at?->toISOString(),
        ];
    }

    private function imageError(string $message, string $code, int $status, string $reason): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'details' => ['reason' => $reason],
        ], $status);
    }

    private function deleteStagedFile(string $path): void
    {
        try {
            $this->deleteFile($path);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function deleteFile(string $path): void
    {
        $disk = Storage::disk('record_images');
        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new \RuntimeException('Failed to delete private Record image storage key: '.$path);
        }
    }
}
