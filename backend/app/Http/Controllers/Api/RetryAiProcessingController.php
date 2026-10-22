<?php

namespace App\Http\Controllers\Api;

use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordResource;
use App\Jobs\ProcessImageJob;
use App\Models\AiProcessing;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class RetryAiProcessingController extends Controller
{
    public function store(Record $record): JsonResponse
    {
        $result = DB::transaction(function () use ($record): AiProcessing|JsonResponse {
            $lockedRecord = Record::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $image = $lockedRecord->image()->lockForUpdate()->first();
            $processing = $image?->aiProcessing()->lockForUpdate()->first();

            if ($lockedRecord->source !== RecordSource::IMAGE
                || $image === null
                || $processing === null
                || $lockedRecord->status !== RecordStatus::ERROR
                || $processing->status !== AiProcessingStatus::ERROR) {
                return response()->json([
                    'message' => 'The AI processing retry is not allowed for this record state.',
                    'code' => 'AI_PROCESSING_RETRY_BLOCKED',
                    'details' => ['reason' => 'INVALID_STATE'],
                ], 409);
            }

            $processing->forceFill([
                'status' => AiProcessingStatus::PENDING,
                'provider' => config('ai.provider'),
                'model' => config('ai.ollama.model'),
                'result' => null,
                'error_message' => null,
                'started_at' => null,
                'completed_at' => null,
            ])->save();
            $lockedRecord->forceFill(['status' => RecordStatus::PENDING])->save();

            return $processing;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        try {
            ProcessImageJob::dispatch($result->getKey(), manualRetry: true)->afterCommit();
        } catch (Throwable) {
            $this->markDispatchFailure($record, $result);
        }

        $record->refresh()->load(['company', 'details', 'image.aiProcessing']);
        $record->loadSum('details as income_total', 'income');
        $record->loadSum('details as commission_total', 'commission');
        $record->loadSum('details as return_total', 'return_amount');

        return (new RecordResource($record))->response()->setStatusCode(202);
    }

    private function markDispatchFailure(Record $record, AiProcessing $processing): void
    {
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
                'result' => [
                    'prompt_version' => 'v4',
                    'normalized_extraction' => null,
                    'issues' => [[
                        'path' => '$',
                        'code' => 'AI_JOB_DISPATCH_FAILED',
                        'severity' => 'error',
                        'message' => 'The extraction job could not be queued.',
                    ]],
                    'unconvertible_numeric_count' => 0,
                ],
            ])->save();
        });
    }
}
