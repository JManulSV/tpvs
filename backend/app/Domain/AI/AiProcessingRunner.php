<?php

namespace App\Domain\AI;

use App\Domain\AI\DTO\AiExtractionResult;
use App\Domain\AI\DTO\AiExtractionValidationResult;
use App\Domain\AI\DTO\ExtractionIssue;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\AiProcessing;
use App\Models\Image;
use App\Models\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One attempt of an image extraction: claim the AiProcessing, call the provider
 * outside any transaction, then persist the outcome or the failure. Every write
 * locks Record → Image → AiProcessing, the same order as the upload paths.
 *
 * The AI only proposes values: a usable extraction leaves the Record in
 * NEEDS_REVIEW, never CONFIRMED.
 */
class AiProcessingRunner
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly ExtractionValidator $validator,
        private readonly RecordExtractionService $extractionService,
    ) {}

    /**
     * @param  int  $attempt  the queue attempt number, starting at 1
     * @param  int  $tries  the attempts the queue allows for this job
     *
     * @throws AiProviderException a transient provider failure the queue should retry
     */
    public function run(int $aiProcessingId, bool $manualRetry, int $attempt, int $tries): void
    {
        $claimed = $this->claimForProcessing($aiProcessingId, $manualRetry, $attempt);
        if ($claimed === null) {
            return;
        }

        [$processing, $image] = $claimed;
        $imagePath = Storage::disk('record_images')->path($image->file_path);

        try {
            $result = $this->aiService->extract($imagePath);
        } catch (AiProviderException $exception) {
            $this->saveProviderFailure($aiProcessingId, $exception);

            if ($exception->transient && ! $manualRetry && $attempt < $tries) {
                throw $exception;
            }

            $this->markFailed($aiProcessingId, $exception->errorCode, $exception->promptVersion, $exception->rawResponse);

            return;
        } catch (Throwable) {
            $this->markFailed($aiProcessingId, 'AI_PROCESSING_FAILED');

            return;
        }

        $validation = $this->validator->validate($result);
        $this->persistOutcome($aiProcessingId, $result, $validation);
    }

    /** Mark the Record and AiProcessing ERROR, unless the extraction already completed. */
    public function markFailed(int $aiProcessingId, string $code, ?string $promptVersion = null, ?string $rawResponse = null): void
    {
        DB::transaction(function () use ($aiProcessingId, $code, $promptVersion, $rawResponse): void {
            $aggregate = $this->lockAggregate($aiProcessingId);
            if ($aggregate === null) {
                return;
            }
            [$processing, $image, $record] = $aggregate;
            if ($processing->status === AiProcessingStatus::COMPLETED || $record->source !== RecordSource::IMAGE) {
                return;
            }

            $result = $processing->result ?? [];
            $result['prompt_version'] = $promptVersion ?? ($result['prompt_version'] ?? 'v5');
            if ($rawResponse !== null) {
                $result['raw_response'] = $rawResponse;
            }
            $result['issues'] = [ExtractionIssue::error('$', $code, 'AI extraction could not be completed.')->toArray()];

            $processing->forceFill([
                'status' => AiProcessingStatus::ERROR,
                'result' => $result,
                'error_message' => $code,
                'completed_at' => now(),
            ])->save();
            $record->forceFill(['status' => RecordStatus::ERROR])->save();
        });
    }

    /**
     * @return array{0: AiProcessing, 1: Image}|null
     */
    private function claimForProcessing(int $aiProcessingId, bool $manualRetry, int $attempt): ?array
    {
        return DB::transaction(function () use ($aiProcessingId, $manualRetry, $attempt): ?array {
            $aggregate = $this->lockAggregate($aiProcessingId);
            if ($aggregate === null) {
                return null;
            }
            [$processing, $image, $record] = $aggregate;

            if ($record->source !== RecordSource::IMAGE) {
                return null;
            }

            $status = $processing->status;
            if ($status === AiProcessingStatus::COMPLETED || $status === AiProcessingStatus::ERROR) {
                return null;
            }

            if ($status === AiProcessingStatus::PROCESSING && $attempt <= 1) {
                return null;
            }

            if ($manualRetry && ($status !== AiProcessingStatus::PENDING || $record->status !== RecordStatus::PENDING)) {
                return null;
            }

            if (! in_array($status, [AiProcessingStatus::PENDING, AiProcessingStatus::PROCESSING], true)
                || ! in_array($record->status, [RecordStatus::PENDING, RecordStatus::PROCESSING], true)) {
                return null;
            }

            $now = now();
            $processing->forceFill([
                'status' => AiProcessingStatus::PROCESSING,
                'provider' => config('ai.provider'),
                'model' => config('ai.ollama.model'),
                'started_at' => $processing->started_at ?? $now,
                'completed_at' => null,
                'error_message' => null,
            ])->save();
            $record->forceFill(['status' => RecordStatus::PROCESSING])->save();

            return [$processing->fresh(), $image];
        });
    }

    private function persistOutcome(int $aiProcessingId, AiExtractionResult $result, AiExtractionValidationResult $validation): void
    {
        DB::transaction(function () use ($aiProcessingId, $result, $validation): void {
            $aggregate = $this->lockAggregate($aiProcessingId);
            if ($aggregate === null) {
                return;
            }
            [$processing, $image, $record] = $aggregate;
            if ($processing->status !== AiProcessingStatus::PROCESSING
                || $record->source !== RecordSource::IMAGE
                || $record->status !== RecordStatus::PROCESSING) {
                return;
            }

            $issues = $validation->issues;
            $usable = $validation->isUsable();
            if ($usable && $validation->normalizedExtraction !== null) {
                $issues = $this->extractionService->apply($record, $validation->normalizedExtraction, $issues);
            }

            $completedAt = now();
            $processing->forceFill([
                'provider' => $result->provider,
                'model' => $result->model,
                'status' => $usable ? AiProcessingStatus::COMPLETED : AiProcessingStatus::ERROR,
                'result' => [
                    'prompt_version' => $result->promptVersion,
                    'raw_response' => $result->rawResponse,
                    'normalized_extraction' => $validation->normalizedExtraction,
                    'issues' => $issues,
                    'unconvertible_numeric_count' => $validation->unconvertibleNumericCount,
                ],
                'error_message' => $usable ? null : $this->validationErrorCode($validation),
                'completed_at' => $completedAt,
            ])->save();

            $record->forceFill([
                'status' => $usable ? RecordStatus::NEEDS_REVIEW : RecordStatus::ERROR,
            ])->save();
        });
    }

    private function saveProviderFailure(int $aiProcessingId, AiProviderException $exception): void
    {
        DB::transaction(function () use ($aiProcessingId, $exception): void {
            $aggregate = $this->lockAggregate($aiProcessingId);
            if ($aggregate === null) {
                return;
            }
            [$processing] = $aggregate;
            if ($processing->status !== AiProcessingStatus::PROCESSING) {
                return;
            }

            $previous = $processing->result ?? [];
            $processing->forceFill([
                'result' => [
                    ...$previous,
                    'prompt_version' => $exception->promptVersion ?? ($previous['prompt_version'] ?? 'v5'),
                    'raw_response' => $exception->rawResponse ?? ($previous['raw_response'] ?? null),
                    'issues' => [ExtractionIssue::error('$', $exception->errorCode, $exception->getMessage())->toArray()],
                ],
                'error_message' => $exception->errorCode,
            ])->save();
        });
    }

    /**
     * Lock aggregate rows in the same Record -> Image -> AIProcessing order used by upload paths.
     *
     * @return array{0: AiProcessing, 1: Image, 2: Record}|null
     */
    private function lockAggregate(int $aiProcessingId): ?array
    {
        $processingSnapshot = AiProcessing::query()->find($aiProcessingId);
        if ($processingSnapshot === null) {
            return null;
        }

        $imageSnapshot = Image::query()->find($processingSnapshot->image_id);
        if ($imageSnapshot === null) {
            return null;
        }

        $record = Record::query()->whereKey($imageSnapshot->record_id)->lockForUpdate()->first();
        $image = Image::query()->whereKey($imageSnapshot->getKey())->lockForUpdate()->first();
        $processing = AiProcessing::query()->whereKey($processingSnapshot->getKey())->lockForUpdate()->first();

        if ($record === null || $image === null || $processing === null
            || $image->record_id !== $record->getKey()
            || $processing->image_id !== $image->getKey()) {
            return null;
        }

        return [$processing, $image, $record];
    }

    private function validationErrorCode(AiExtractionValidationResult $validation): string
    {
        return $validation->structurallyValid
            ? 'AI_EXTRACTION_NOT_USABLE'
            : 'AI_EXTRACTION_INVALID_STRUCTURE';
    }
}
