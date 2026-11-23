<?php

namespace Tests\Unit\AI;

use App\Domain\AI\DTO\ExtractionIssue;
use PHPUnit\Framework\TestCase;

class ExtractionIssueTest extends TestCase
{
    public function test_without_a_value_the_array_has_no_value_key(): void
    {
        $this->assertSame(
            ['path' => '$', 'code' => 'AI_PROCESSING_FAILED', 'severity' => 'error', 'message' => 'Failed.'],
            ExtractionIssue::error('$', 'AI_PROCESSING_FAILED', 'Failed.')->toArray(),
        );
    }

    public function test_a_given_value_is_kept_even_when_it_is_null(): void
    {
        $this->assertSame(
            ['path' => 'record.year', 'code' => 'INVALID_YEAR', 'severity' => 'review', 'message' => 'Out of range.', 'value' => 0],
            ExtractionIssue::review('record.year', 'INVALID_YEAR', 'Out of range.')->withValue(0)->toArray(),
        );
        $this->assertArrayHasKey('value', ExtractionIssue::review('details.0.income', 'X', 'Y')->withValue(null)->toArray());
    }
}
