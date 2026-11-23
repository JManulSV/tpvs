<?php

namespace App\Http\Controllers\Api;

use App\Domain\Images\Actions\ReplaceRecordImage;
use App\Domain\Images\Exceptions\RecordImageBlocked;
use App\Domain\Shared\Exceptions\DomainRuleException;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\UploadRecordImageRequest;
use App\Models\Image;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordImageController extends Controller
{
    public function store(
        UploadRecordImageRequest $request,
        Record $record,
        ReplaceRecordImage $replaceRecordImage,
    ): JsonResponse {
        try {
            ['image' => $image, 'replaced' => $replaced] = $replaceRecordImage->handle($record, $request->file('image'));
        } catch (DomainRuleException|ValidationException $expected) {
            throw $expected;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The image could not be stored.',
            ], 500);
        }

        return response()->json([
            'data' => $this->metadata($image),
        ], $replaced ? 200 : 201);
    }

    public function show(Request $request, Record $record): Response|StreamedResponse
    {
        Gate::authorize('viewImage', $record);

        if ($record->status === RecordStatus::CANCELED) {
            throw RecordImageBlocked::accessForCanceledRecord();
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
}
