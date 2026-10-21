<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** The processing lifecycle is implemented in the queue-policy step. */
class ProcessImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 330;

    public function __construct(public readonly int $aiProcessingId) {}

    public function handle(): void
    {
        // The processing lifecycle is implemented in the queue-policy step.
    }
}
