<?php

namespace Tests\Unit\AI;

use App\Domain\AI\Actions\RetryAiProcessing;
use App\Domain\AI\Exceptions\AiProcessingRetryBlocked;
use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Jobs\ProcessImageJob;
use App\Models\AiProcessing;
use App\Models\Image;
use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryAiProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_extraction_is_reset_to_pending_and_queued_as_a_manual_retry(): void
    {
        Queue::fake();
        [$record, $processing] = $this->imageRecord(RecordStatus::ERROR, AiProcessingStatus::ERROR);

        $retried = app(RetryAiProcessing::class)->handle($record);

        $this->assertSame(RecordStatus::PENDING, $retried->status);
        $processing->refresh();
        $this->assertSame(AiProcessingStatus::PENDING, $processing->status);
        $this->assertNull($processing->error_message);
        $this->assertNull($processing->result);
        Queue::assertPushed(ProcessImageJob::class, fn (ProcessImageJob $job): bool => $job->aiProcessingId === $processing->id
            && $job->manualRetry);
    }

    public function test_a_record_that_is_not_in_error_cannot_be_retried(): void
    {
        Queue::fake();
        [$record] = $this->imageRecord(RecordStatus::PENDING, AiProcessingStatus::PENDING);

        try {
            app(RetryAiProcessing::class)->handle($record);
            $this->fail('Expected the retry to be blocked.');
        } catch (AiProcessingRetryBlocked) {
            $this->assertSame(RecordStatus::PENDING, $record->refresh()->status);
        }

        Queue::assertNothingPushed();
    }

    /**
     * @return array{Record, AiProcessing}
     */
    private function imageRecord(RecordStatus $recordStatus, AiProcessingStatus $processingStatus): array
    {
        $record = Record::factory()->create(['source' => RecordSource::IMAGE, 'status' => $recordStatus]);
        $image = Image::factory()->create(['record_id' => $record->id]);
        $processing = AiProcessing::factory()->create(['image_id' => $image->id, 'status' => $processingStatus]);

        return [$record, $processing];
    }
}
