<?php

namespace Tests\Feature;

use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessImageJob;
use App\Models\AiProcessing;
use App\Models\Image;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EnforceCsrfToken;
use Tests\TestCase;

class AiProcessingRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.middleware.validate_csrf_token' => EnforceCsrfToken::class]);
    }

    public function test_editor_can_retry_failed_image_processing_once_using_the_existing_row(): void
    {
        Queue::fake();
        [$record, $image, $processing] = $this->failedAggregate();
        $processing->forceFill(['result' => ['raw_response' => 'old response']])->save();

        $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->postJson('/api/records/'.$record->id.'/ai-processing/retry', [])
            ->assertAccepted()
            ->assertJsonPath('data.status', RecordStatus::PENDING->value)
            ->assertJsonPath('data.ai_processing.status', AiProcessingStatus::PENDING->value)
            ->assertJsonPath('data.ai_processing.normalized_extraction', null);

        $this->assertSame(1, AiProcessing::query()->where('image_id', $image->id)->count());
        $this->assertNull($processing->fresh()->result);
        $this->assertDatabaseCount('histories', 0);
        Queue::assertPushed(ProcessImageJob::class, fn (ProcessImageJob $job): bool => $job->manualRetry && $job->tries === 1);
    }

    public function test_retry_requires_editor_and_authentication(): void
    {
        [$record] = $this->failedAggregate();

        $this->postJson('/api/records/'.$record->id.'/ai-processing/retry', [])->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => UserRole::VIEWER]), 'web')
            ->postJson('/api/records/'.$record->id.'/ai-processing/retry', [])
            ->assertForbidden();
    }

    public function test_stateful_editor_retry_requires_valid_csrf_token(): void
    {
        Queue::fake();
        [$record] = $this->failedAggregate();
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);

        $this->withSession(['_token' => 'expected-csrf-token'])
            ->actingAs($editor, 'web')
            ->post('/api/records/'.$record->id.'/ai-processing/retry', [], [
                'Origin' => 'http://localhost:3000',
                'X-CSRF-TOKEN' => 'invalid-csrf-token',
            ])
            ->assertStatus(419);

        $this->assertSame(RecordStatus::ERROR, $record->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_retry_is_rejected_unless_the_image_record_and_processing_are_in_error(): void
    {
        Queue::fake();
        [$record, $image, $processing] = $this->failedAggregate();
        $record->forceFill(['status' => RecordStatus::NEEDS_REVIEW])->save();

        $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->postJson('/api/records/'.$record->id.'/ai-processing/retry', [])
            ->assertConflict()
            ->assertJsonPath('code', 'AI_PROCESSING_RETRY_BLOCKED');

        $this->assertSame(AiProcessingStatus::ERROR, $processing->fresh()->status);
        Queue::assertNothingPushed();
    }

    /** @return array{0: Record, 1: Image, 2: AiProcessing} */
    private function failedAggregate(): array
    {
        $record = Record::factory()->create([
            'company_id' => null,
            'source' => RecordSource::IMAGE,
            'status' => RecordStatus::ERROR,
            'year' => null,
            'week' => null,
            'week_start_date' => null,
            'week_end_date' => null,
        ]);
        $image = Image::factory()->for($record)->create();
        $processing = AiProcessing::factory()->for($image)->create([
            'status' => AiProcessingStatus::ERROR,
            'error_message' => 'AI_PROVIDER_SERVER_ERROR',
        ]);

        return [$record, $image, $processing];
    }
}
