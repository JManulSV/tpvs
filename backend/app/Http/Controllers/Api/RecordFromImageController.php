<?php

namespace App\Http\Controllers\Api;

use App\Domain\Images\RecordImageStorage;
use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\StoreImageRecordRequest;
use App\Http\Resources\RecordResource;
use App\Jobs\ProcessImageJob;
use App\Models\AiProcessing;
use App\Models\Image;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RecordFromImageController extends Controller
{
    public function store(StoreImageRecordRequest $request): JsonResponse
    {
        try {
            $stored = app(RecordImageStorage::class)->store($request->file('image'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'The image could not be stored.'], 500);
        }

        try {
            [$record, $processing] = DB::transaction(function () use ($stored): array {
                $record = Record::query()->create([
                    'company_id' => null,
                    'source' => RecordSource::IMAGE,
                    'status' => RecordStatus::PENDING,
                    'year' => null,
                    'week' => null,
                    'week_start_date' => null,
                    'week_end_date' => null,
                ]);

                $image = new Image($stored);
                $image->record()->associate($record);
                $image->save();

                $processing = $image->aiProcessing()->create([
                    'provider' => config('ai.provider'),
                    'model' => config('ai.ollama.model'),
                    'status' => AiProcessingStatus::PENDING,
                ]);

                return [$record, $processing];
            });
        } catch (\Throwable $exception) {
            $this->deleteStagedFile($stored['file_path']);
            report($exception);

            return response()->json(['message' => 'The image record could not be created.'], 500);
        }

        try {
            ProcessImageJob::dispatch($processing->getKey());
        } catch (\Throwable $exception) {
            report($exception);
            DB::transaction(function () use ($record, $processing): void {
                $lockedRecord = Record::query()->whereKey($record->getKey())->lockForUpdate()->first();
                $lockedProcessing = AiProcessing::query()->whereKey($processing->getKey())->lockForUpdate()->first();
                if ($lockedRecord === null || $lockedProcessing === null) {
                    return;
                }

                $lockedRecord->forceFill(['status' => RecordStatus::ERROR])->save();
                $lockedProcessing->forceFill([
                    'status' => AiProcessingStatus::ERROR,
                    'error_message' => 'AI_JOB_DISPATCH_FAILED',
                    'completed_at' => now(),
                ])->save();
            });
        }

        $record->load(['company', 'details', 'image.aiProcessing']);
        $record->loadSum('details as income_total', 'income');
        $record->loadSum('details as commission_total', 'commission');
        $record->loadSum('details as return_total', 'return_amount');

        return (new RecordResource($record))->response()->setStatusCode(202);
    }

    private function deleteStagedFile(string $path): void
    {
        try {
            $disk = Storage::disk('record_images');
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
