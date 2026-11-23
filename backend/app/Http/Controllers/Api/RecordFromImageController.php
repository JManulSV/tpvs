<?php

namespace App\Http\Controllers\Api;

use App\Domain\AI\Actions\CreateRecordFromImage;
use App\Domain\Images\RecordImageStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\StoreImageRecordRequest;
use App\Http\Resources\RecordResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RecordFromImageController extends Controller
{
    public function store(
        StoreImageRecordRequest $request,
        RecordImageStorage $imageStorage,
        CreateRecordFromImage $createRecordFromImage,
    ): JsonResponse {
        try {
            $stored = $imageStorage->store($request->file('image'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'The image could not be stored.'], 500);
        }

        try {
            $record = $createRecordFromImage->handle($stored, $request->user()->id);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'The image record could not be created.'], 500);
        }

        return (new RecordResource($record->loadResourceAggregates()))->response()->setStatusCode(202);
    }
}
