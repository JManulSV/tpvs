<?php

namespace App\Jobs;

use App\Domain\AI\AiProcessingRunner;
use App\Domain\AI\AIService;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\ExtractionValidator;
use App\Domain\AI\RecordExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Executes one logical image extraction, with bounded retries for transient provider
 * failures. The extraction itself lives in AiProcessingRunner.
 */
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
        (new AiProcessingRunner($aiService, $validator, $extractionService))
            ->run($this->aiProcessingId, $this->manualRetry, $this->currentAttempt(), $this->tries);
    }

    public function failed(?Throwable $exception): void
    {
        $code = $exception instanceof AiProviderException ? $exception->errorCode : 'AI_PROCESSING_FAILED';
        $promptVersion = $exception instanceof AiProviderException ? $exception->promptVersion : null;
        $rawResponse = $exception instanceof AiProviderException ? $exception->rawResponse : null;

        app(AiProcessingRunner::class)->markFailed($this->aiProcessingId, $code, $promptVersion, $rawResponse);
    }

    private function currentAttempt(): int
    {
        return $this->job?->attempts() ?? 1;
    }
}
