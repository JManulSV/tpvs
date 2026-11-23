<?php

namespace App\Domain\AI\Actions;

use App\Domain\AI\AiJobDispatcher;
use App\Domain\AI\Exceptions\AiProcessingRetryBlocked;
use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\AiProcessing;
use App\Models\Record;
use Illuminate\Support\Facades\DB;

/**
 * Reset an image Record and its AiProcessing from ERROR to PENDING and queue the
 * extraction again as a manual retry.
 */
class RetryAiProcessing
{
    public function __construct(private readonly AiJobDispatcher $dispatcher) {}

    public function handle(Record $record): Record
    {
        $processing = DB::transaction(function () use ($record): AiProcessing {
            $lockedRecord = Record::findLockedOrFail($record->getKey());
            $image = $lockedRecord->image()->lockForUpdate()->first();
            $processing = $image?->aiProcessing()->lockForUpdate()->first();

            if ($lockedRecord->source !== RecordSource::IMAGE
                || $image === null
                || $processing === null
                || $lockedRecord->status !== RecordStatus::ERROR
                || $processing->status !== AiProcessingStatus::ERROR) {
                throw AiProcessingRetryBlocked::invalidState();
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

        $this->dispatcher->dispatch($record, $processing, manualRetry: true);

        return $record->refresh();
    }
}
