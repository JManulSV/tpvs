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
        $this->assertSame('{invalid', $processing->fresh()->result['raw_response']);
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
            promptVersion: 'v4',
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

        return new AiExtractionResult('ollama', 'qwen2.5vl:3b', 'v4', 'private provider response', $responseText, $extraction);
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
                'source_return_amount' => ['value' => '9800.00', 'confidence' => 0.95],
            ]],
            'source_totals' => [
                'income' => ['value' => '10000.00', 'confidence' => 0.95],
                'commission' => ['value' => '200.00', 'confidence' => 0.95],
                'return_amount' => ['value' => '9800.00', 'confidence' => 0.95],
            ],
        ];
    }
}
