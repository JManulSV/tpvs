<?php

namespace Tests\Unit;

use App\Jobs\ProcessImageJob;
use Tests\TestCase;

class ProcessImageJobPolicyTest extends TestCase
{
    public function test_initial_cycle_allows_three_total_attempts_and_uses_worker_timeout(): void
    {
        $job = new ProcessImageJob(10);

        $this->assertSame(3, $job->tries);
        $this->assertSame(330, $job->timeout);
    }

    public function test_manual_retry_job_allows_one_attempt_only(): void
    {
        $job = new ProcessImageJob(10, manualRetry: true);

        $this->assertSame(1, $job->tries);
    }

    public function test_database_queue_visibility_timeout_exceeds_job_timeout(): void
    {
        $this->assertGreaterThan(330, config('queue.connections.database.retry_after'));
    }
}
