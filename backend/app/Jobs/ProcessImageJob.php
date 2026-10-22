<?php

namespace App\Jobs;

use App\Domain\AI\AIService;
use App\Domain\AI\DTO\AiExtractionResult;
use App\Domain\AI\DTO\AiExtractionValidationResult;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\ExtractionValidator;
use App\Domain\AI\RecordExtractionService;
use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\AiProcessing;
use App\Models\Image;
use App\Models\Record;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Executes one logical image extraction, with bounded retries for transient provider failures. */
class ProcessImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout = 330;

    public function __construct(
        public readonly int $aiProcessingId,
        public readonly bool $manualRetry = false,
    ) {
        $this->tries = $manualRetry ? 1 : 3;
    }

    public function handle(AIService $aiService, ExtractionValidator $validator, RecordExtractionService $extractionService): void
    {
        $claimed = $this->claimForProcessing();
        if ($claimed === null) {
            return;
        }

        [$processing, $image] = $claimed;
        $imagePath = Storage::disk('record_images')->path($image->file_path);

        try {
            $result = $aiService->extract($imagePath);
        } catch (AiProviderException $exception) {
            $this->saveProviderFailure($exception);

            if ($exception->transient && ! $this->manualRetry && $this->currentAttempt() < $this->tries) {
                throw $exception;
            }

            $this->markFailed($exception->errorCode, $exception->promptVersion, $exception->rawResponse);

            return;
        } catch (Throwable) {
            $this->markFailed('AI_PROCESSING_FAILED');

            return;
        }

        $validation = $validator->validate($result);
        $this->persistOutcome($result, $validation, $extractionService);
    }

    public function failed(?Throwable $exception): void
    {
        $code = $exception instanceof AiProviderException ? $exception->errorCode : 'AI_PROCESSING_FAILED';
        $promptVersion = $exception instanceof AiProviderException ? $exception->promptVersion : null;
        $rawResponse = $exception instanceof AiProviderException ? $exception->rawResponse : null;

        $this->markFailed($code, $promptVersion, $rawResponse);
    }

    /**
     * @return array{0: AiProcessing, 1: Image}|null
     */
    private function claimForProcessing(): ?array
    {
        return DB::transaction(function (): ?array {
            $aggregate = $this->lockAggregate();
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

            if ($status === AiProcessingStatus::PROCESSING && $this->currentAttempt() <= 1) {
                return null;
            }

            if ($this->manualRetry && ($status !== AiProcessingStatus::PENDING || $record->status !== RecordStatus::PENDING)) {
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

    private function persistOutcome(AiExtractionResult $result, AiExtractionValidationResult $validation, RecordExtractionService $extractionService): void
    {
        DB::transaction(function () use ($result, $validation, $extractionService): void {
            $aggregate = $this->lockAggregate();
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
                $issues = $extractionService->apply($record, $validation->normalizedExtraction, $issues);
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

    private function saveProviderFailure(AiProviderException $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $aggregate = $this->lockAggregate();
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
                    'prompt_version' => $exception->promptVersion ?? ($previous['prompt_version'] ?? 'v4'),
                    'raw_response' => $exception->rawResponse ?? ($previous['raw_response'] ?? null),
                    'issues' => [[
                        'path' => '$',
                        'code' => $exception->errorCode,
                        'severity' => 'error',
                        'message' => $exception->getMessage(),
                    ]],
                ],
                'error_message' => $exception->errorCode,
            ])->save();
        });
    }

    private function markFailed(string $code, ?string $promptVersion = null, ?string $rawResponse = null): void
    {
        DB::transaction(function () use ($code, $promptVersion, $rawResponse): void {
            $aggregate = $this->lockAggregate();
            if ($aggregate === null) {
                return;
            }
            [$processing, $image, $record] = $aggregate;
            if ($processing->status === AiProcessingStatus::COMPLETED || $record->source !== RecordSource::IMAGE) {
                return;
            }

            $result = $processing->result ?? [];
            $result['prompt_version'] = $promptVersion ?? ($result['prompt_version'] ?? 'v4');
            if ($rawResponse !== null) {
                $result['raw_response'] = $rawResponse;
            }
            $result['issues'] = [[
                'path' => '$',
                'code' => $code,
                'severity' => 'error',
                'message' => 'AI extraction could not be completed.',
            ]];

            $processing->forceFill([
                'status' => AiProcessingStatus::ERROR,
                'result' => $result,
                'error_message' => $code,
                'completed_at' => now(),
            ])->save();
            $record->forceFill(['status' => RecordStatus::ERROR])->save();
        });
    }

    private function currentAttempt(): int
    {
        return $this->job?->attempts() ?? 1;
    }

    /**
     * Lock aggregate rows in the same Record -> Image -> AIProcessing order used by upload paths.
     *
     * @return array{0: AiProcessing, 1: Image, 2: Record}|null
     */
    private function lockAggregate(): ?array
    {
        $processingSnapshot = AiProcessing::query()->find($this->aiProcessingId);
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
