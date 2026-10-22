<?php

namespace Tests\Feature;

use App\Domain\AI\AIService;
use App\Domain\AI\DTO\AiExtractionResult;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\ExtractionValidator;
use App\Domain\AI\RecordExtractionService;
use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Jobs\ProcessImageJob;
use App\Models\AiProcessing;
use App\Models\Company;
use App\Models\Image;
use App\Models\Record;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessImageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_usable_extraction_is_persisted_for_human_review_without_confirmation(): void
    {
        Storage::fake('record_images');
        $company = Company::factory()->create(['name' => 'Example Co']);
        [$record, $image, $processing] = $this->aggregate();
        $extraction = $this->extraction(' example co ');
        $this->mock(AIService::class, function ($mock) use ($extraction): void {
            $mock->shouldReceive('extract')->once()->andReturn($this->makeResult($extraction));
        });

        (new ProcessImageJob($processing->id))->handle(
            app(AIService::class),
            app(ExtractionValidator::class),
            app(RecordExtractionService::class),
        );

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertSame($company->id, $record->fresh()->company_id);
        $this->assertSame(AiProcessingStatus::COMPLETED, $processing->fresh()->status);
        $this->assertSame($extraction, $processing->fresh()->result['normalized_extraction']);
        $this->assertSame('private provider response', $processing->fresh()->result['raw_response']);
        $this->assertDatabaseCount('histories', 0);

        (new ProcessImageJob($processing->id))->handle(
            app(AIService::class),
            app(ExtractionValidator::class),
            app(RecordExtractionService::class),
        );
    }

    public function test_duplicate_job_after_completion_does_not_call_provider_or_overwrite_result(): void
    {
        Storage::fake('record_images');
        [$record, $image, $processing] = $this->aggregate();
        $extraction = $this->extraction('Example Co');
        $this->mock(AIService::class, function ($mock) use ($extraction): void {
            $mock->shouldReceive('extract')->once()->andReturn($this->makeResult($extraction));
        });

        $this->runJob($processing->id);
        $firstResult = $processing->fresh()->result;
        $firstCompletedAt = $processing->fresh()->completed_at;

        $this->runJob($processing->id);

        $this->assertSame(AiProcessingStatus::COMPLETED, $processing->fresh()->status);
        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertSame($firstResult, $processing->fresh()->result);
        $this->assertTrue($firstCompletedAt->equalTo($processing->fresh()->completed_at));
    }

    public function test_structural_failure_finishes_in_error_without_applying_details(): void
    {
        Storage::fake('record_images');
        [$record, $image, $processing] = $this->aggregate();
        $this->mock(AIService::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andReturn($this->makeResult(null, '{invalid'));
        });

        (new ProcessImageJob($processing->id))->handle(
            app(AIService::class),
            app(ExtractionValidator::class),
            app(RecordExtractionService::class),
        );

        $this->assertSame(RecordStatus::ERROR, $record->fresh()->status);
        $this->assertSame(AiProcessingStatus::ERROR, $processing->fresh()->status);
        $this->assertDatabaseCount('record_details', 0);
        $this->assertSame('private provider response', $processing->fresh()->result['raw_response']);
    }

    public function test_four_unconvertible_numeric_values_still_persist_usable_fields(): void
    {
        Storage::fake('record_images');
        [$record, $image, $processing] = $this->aggregate();
        $extraction = $this->extraction('Example Co');
        $extraction['details'][0]['source_commission']['value'] = '1,200';
        $extraction['source_totals']['income']['value'] = '1,200';
        $extraction['source_totals']['commission']['value'] = '1.200';
        $extraction['source_totals']['return_amount']['value'] = '1,200';
        $this->mock(AIService::class, function ($mock) use ($extraction): void {
            $mock->shouldReceive('extract')->once()->andReturn($this->makeResult($extraction));
        });

        $this->runJob($processing->id);

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertSame(AiProcessingStatus::COMPLETED, $processing->fresh()->status);
        $this->assertSame(4, $processing->fresh()->result['unconvertible_numeric_count']);
        $this->assertSame('10000.00', $record->fresh()->details()->firstOrFail()->income);
    }

    public function test_five_unconvertible_numeric_values_fail_without_applying_any_detail(): void
    {
        Storage::fake('record_images');
        [$record, $image, $processing] = $this->aggregate();
        $extraction = $this->extraction('Example Co');
        $extraction['details'][0]['income']['value'] = '55.963.03';
        $extraction['details'][0]['source_commission']['value'] = '1,200';
        $extraction['source_totals']['income']['value'] = '1,200';
        $extraction['source_totals']['commission']['value'] = '1.200';
        $extraction['source_totals']['return_amount']['value'] = '1,200';
        $this->mock(AIService::class, function ($mock) use ($extraction): void {
            $mock->shouldReceive('extract')->once()->andReturn($this->makeResult($extraction));
        });

        $this->runJob($processing->id);

        $this->assertSame(RecordStatus::ERROR, $record->fresh()->status);
        $this->assertSame(AiProcessingStatus::ERROR, $processing->fresh()->status);
        $this->assertSame(5, $processing->fresh()->result['unconvertible_numeric_count']);
        $this->assertSame(0, $record->fresh()->details()->count());
    }

    public function test_transient_failure_is_kept_for_retry_until_final_attempt(): void
    {
        Storage::fake('record_images');
        [$record, $image, $processing] = $this->aggregate();
        $service = Mockery::mock(AIService::class);
        $service->shouldReceive('extract')->once()->andThrow(new AiProviderException(
            'AI_PROVIDER_CONNECTION_FAILED',
            'The AI provider could not be reached or timed out.',
            true,
            promptVersion: 'v5',
        ));
        $this->app->instance(AIService::class, $service);

        try {
            (new ProcessImageJob($processing->id))->handle(
                app(AIService::class),
                app(ExtractionValidator::class),
                app(RecordExtractionService::class),
            );
            $this->fail('Transient failure must be retried by the queue.');
        } catch (AiProviderException $exception) {
            $this->assertTrue($exception->transient);
        }

        $this->assertSame(RecordStatus::PROCESSING, $record->fresh()->status);
        $this->assertSame(AiProcessingStatus::PROCESSING, $processing->fresh()->status);
        $this->assertSame('AI_PROVIDER_CONNECTION_FAILED', $processing->fresh()->error_message);
    }

    public function test_initial_cycle_stops_after_three_transient_provider_calls(): void
    {
        Storage::fake('record_images');
        [$record, $image, $processing] = $this->aggregate();
        $provider = Mockery::mock(AIService::class);
        $provider->shouldReceive('extract')->times(3)->andThrow(new AiProviderException(
            'AI_PROVIDER_SERVER_ERROR',
            'The AI provider returned a server error.',
            true,
            rawResponse: 'provider error body',
            promptVersion: 'v5',
            httpStatus: 503,
        ));
        $this->app->instance(AIService::class, $provider);

        foreach ([1, 2] as $attempt) {
            $queueJob = Mockery::mock(QueueJobContract::class);
            $queueJob->shouldReceive('attempts')->andReturn($attempt);
            $job = (new ProcessImageJob($processing->id))->setJob($queueJob);

            try {
                $job->handle(app(AIService::class), app(ExtractionValidator::class), app(RecordExtractionService::class));
                $this->fail('Transient provider errors before the final attempt should be retried.');
            } catch (AiProviderException $exception) {
                $this->assertTrue($exception->transient);
            }
        }

        $finalQueueJob = Mockery::mock(QueueJobContract::class);
        $finalQueueJob->shouldReceive('attempts')->andReturn(3);
        $finalJob = (new ProcessImageJob($processing->id))->setJob($finalQueueJob);
        $finalJob->handle(app(AIService::class), app(ExtractionValidator::class), app(RecordExtractionService::class));

        $this->assertSame(RecordStatus::ERROR, $record->fresh()->status);
        $this->assertSame(AiProcessingStatus::ERROR, $processing->fresh()->status);
        $this->assertSame('AI_PROVIDER_SERVER_ERROR', $processing->fresh()->error_message);
        $this->assertSame('provider error body', $processing->fresh()->result['raw_response']);
    }

    public function test_nontransient_provider_error_does_not_retry(): void
    {
        Storage::fake('record_images');
        [$record, $image, $processing] = $this->aggregate();
        $provider = Mockery::mock(AIService::class);
        $provider->shouldReceive('extract')->once()->andThrow(new AiProviderException(
            'AI_PROVIDER_REQUEST_REJECTED',
            'The AI provider rejected the extraction request.',
            false,
            httpStatus: 404,
        ));
        $this->app->instance(AIService::class, $provider);

        $this->runJob($processing->id);

        $this->assertSame(RecordStatus::ERROR, $record->fresh()->status);
        $this->assertSame(AiProcessingStatus::ERROR, $processing->fresh()->status);
        $this->assertSame('AI_PROVIDER_REQUEST_REJECTED', $processing->fresh()->error_message);
    }

    /** @return array{0: Record, 1: Image, 2: AiProcessing} */
    private function aggregate(): array
    {
        $record = Record::factory()->create([
            'company_id' => null,
            'source' => RecordSource::IMAGE,
            'status' => RecordStatus::PENDING,
            'year' => null,
            'week' => null,
            'week_start_date' => null,
            'week_end_date' => null,
        ]);
        Storage::disk('record_images')->put('image.png', 'image-bytes');
        $image = Image::factory()->for($record)->create(['file_path' => 'image.png']);
        $processing = AiProcessing::factory()->for($image)->create([
            'status' => AiProcessingStatus::PENDING,
            'result' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        return [$record, $image, $processing];
    }

    private function makeResult(?array $extraction, ?string $responseText = null): AiExtractionResult
    {
        $responseText ??= json_encode($extraction, JSON_THROW_ON_ERROR);

        return new AiExtractionResult('ollama', 'qwen2.5vl:3b', 'v5', 'private provider response', $responseText, $extraction);
    }

    private function runJob(int $processingId): void
    {
        (new ProcessImageJob($processingId))->handle(
            app(AIService::class),
            app(ExtractionValidator::class),
            app(RecordExtractionService::class),
        );
    }

    /** @return array<string, mixed> */
    private function extraction(string $companyName): array
    {
        return [
            'record' => [
                'company_name' => ['value' => $companyName, 'confidence' => 0.95],
                'year' => ['value' => 2026, 'confidence' => 0.95],
                'week' => ['value' => 38, 'confidence' => 0.95],
                'week_start_date' => ['value' => '2026-09-14', 'confidence' => 0.95],
                'week_end_date' => ['value' => '2026-09-20', 'confidence' => 0.95],
            ],
            'details' => [[
                'affiliation' => ['value' => 'AFF-123', 'confidence' => 0.95],
                'branch' => ['value' => 'Main', 'confidence' => 0.95],
                'income' => ['value' => '10000.00', 'confidence' => 0.95],
                'commission_percentage' => ['value' => '2.00', 'confidence' => 0.95],
                'source_commission' => ['value' => '200.00', 'confidence' => 0.95],
            ]],
            'source_totals' => [
                'income' => ['value' => '10000.00', 'confidence' => 0.95],
                'commission' => ['value' => '200.00', 'confidence' => 0.95],
                'return_amount' => ['value' => '9800.00', 'confidence' => 0.95],
            ],
        ];
    }
}
