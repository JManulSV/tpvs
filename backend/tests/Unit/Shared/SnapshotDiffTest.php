<?php

namespace Tests\Unit\Shared;

use App\Domain\Shared\History\SnapshotDiff;
use PHPUnit\Framework\TestCase;

class SnapshotDiffTest extends TestCase
{
    public function test_identical_snapshots_have_no_changes(): void
    {
        $snapshot = ['year' => '2026', 'company' => 'Acme', 'note' => null];

        $this->assertSame([], SnapshotDiff::diff($snapshot, $snapshot));
    }

    public function test_it_reports_changed_added_and_removed_fields_in_first_seen_order(): void
    {
        $this->assertSame([
            ['field_name' => 'week', 'previous_value' => '37', 'new_value' => '38'],
            ['field_name' => 'details[2].income', 'previous_value' => '10.00', 'new_value' => null],
            ['field_name' => 'details[3].income', 'previous_value' => null, 'new_value' => '20.00'],
        ], SnapshotDiff::diff(
            ['year' => '2026', 'week' => '37', 'details[2].income' => '10.00'],
            ['year' => '2026', 'week' => '38', 'details[3].income' => '20.00'],
        ));
    }

    public function test_a_missing_field_equals_a_null_field(): void
    {
        $this->assertSame([], SnapshotDiff::diff(['received_by_name' => null], []));
    }

    public function test_comparison_is_strict(): void
    {
        $this->assertSame(
            [['field_name' => 'amount', 'previous_value' => '1000', 'new_value' => '1000.00']],
            SnapshotDiff::diff(['amount' => '1000'], ['amount' => '1000.00']),
        );
    }
}
