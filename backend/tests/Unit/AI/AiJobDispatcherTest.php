<?php

namespace Tests\Unit\AI;

use App\Domain\AI\AiJobDispatcher;
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

class AiJobDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_the_extraction_job(): void
    {
        Queue::fake();
        [$record, $processing] = $this->pendingImageRecord();

        app(AiJobDispatcher::class)->dispatch($record, $processing, manualRetry: true);

        Queue::assertPushed(ProcessImageJob::class, fn (ProcessImageJob $job): bool => $job->aiProcessingId === $processing->id
            && $job->manualRetry);
    }

    public function test_a_dispatch_failure_marks_both_rows_error_without_throwing(): void
    {
        $this->breakTheQueue();
        [$record, $processing] = $this->pendingImageRecord();

        app(AiJobDispatcher::class)->dispatch($record, $processing);

        $this->assertSame(RecordStatus::ERROR, $record->refresh()->status);
        $processing->refresh();
        $this->assertSame(AiProcessingStatus::ERROR, $processing->status);
        $this->assertSame(AiJobDispatcher::DISPATCH_FAILED, $processing->error_message);
        $this->assertNotNull($processing->completed_at);
        $this->assertSame(AiJobDispatcher::DISPATCH_FAILED, $processing->result['issues'][0]['code']);
    }

    public function test_a_dispatch_failure_for_rows_that_no_longer_exist_is_ignored(): void
    {
        $this->breakTheQueue();
        [$record, $processing] = $this->pendingImageRecord();
        $processing->delete();

        app(AiJobDispatcher::class)->dispatch($record, $processing);

        $this->assertSame(RecordStatus::PENDING, $record->refresh()->status);
    }

    private function breakTheQueue(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'not-a-real-queue-driver',
        ]);
    }

    /**
     * @return array{Record, AiProcessing}
     */
    private function pendingImageRecord(): array
    {
        $record = Record::factory()->create(['source' => RecordSource::IMAGE, 'status' => RecordStatus::PENDING]);
        $image = Image::factory()->create(['record_id' => $record->id]);
        $processing = AiProcessing::factory()->create([
            'image_id' => $image->id,
            'status' => AiProcessingStatus::PENDING,
        ]);

        return [$record, $processing];
    }
}
