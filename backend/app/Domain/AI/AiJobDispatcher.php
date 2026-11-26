<?php

namespace App\Domain\AI;

use App\Domain\AI\DTO\ExtractionIssue;
use App\Enums\AiProcessingStatus;
use App\Enums\RecordStatus;
use App\Jobs\ProcessImageJob;
use App\Models\AiProcessing;
use App\Models\Record;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Queue ProcessImageJob after the current transaction commits. A dispatch failure
 * does not throw: the Record and its AiProcessing are marked ERROR with the
 * persisted AI_JOB_DISPATCH_FAILED issue, so the caller can still answer 202.
 */
class AiJobDispatcher
{
    public const DISPATCH_FAILED = 'AI_JOB_DISPATCH_FAILED';

    public function dispatch(Record $record, AiProcessing $processing, bool $manualRetry = false): void
    {
        try {
            ProcessImageJob::dispatch($processing->getKey(), manualRetry: $manualRetry)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
            $this->markDispatchFailure($record, $processing);
        }
    }

    private function markDispatchFailure(Record $record, AiProcessing $processing): void
    {
        DB::transaction(function () use ($record, $processing): void {
            // Null-tolerant on purpose: the rows may be gone by the time the failure is handled.
            $lockedRecord = Record::query()->whereKey($record->getKey())->lockForUpdate()->first();
            $lockedProcessing = AiProcessing::query()->whereKey($processing->getKey())->lockForUpdate()->first();
            if ($lockedRecord === null || $lockedProcessing === null) {
                return;
            }

            $lockedRecord->forceFill(['status' => RecordStatus::ERROR])->save();
            $lockedProcessing->forceFill([
                'status' => AiProcessingStatus::ERROR,
                'error_message' => self::DISPATCH_FAILED,
                'completed_at' => now(),
                'result' => [
                    'prompt_version' => OllamaAIService::PROMPT_VERSION,
                    'normalized_extraction' => null,
                    'issues' => [ExtractionIssue::error('$', self::DISPATCH_FAILED, 'The extraction job could not be queued.')->toArray()],
                    'unconvertible_numeric_count' => 0,
                ],
            ])->save();
        });
    }
}
