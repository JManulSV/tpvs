<?php

namespace App\Domain\Records;

use App\Domain\Shared\History\CanonicalValue;
use App\Domain\Shared\History\SnapshotDiff;
use App\Enums\RecordStatus;
use App\Models\History;
use App\Models\Record;

class RecordHistoryService
{
    private const DETAIL_TEXT_FIELDS = [
        'affiliation',
        'bank',
    ];

    private const DETAIL_DECIMAL_FIELDS = [
        'income',
    ];

    /**
     * Build a canonical snapshot of the record and its ordered details.
     *
     * The Record's commission and return_amount are left out on purpose: they
     * are derived from the percentage and the incomes, which are tracked instead.
     *
     * @return array<string, string|null>
     */
    public function snapshot(Record $record): array
    {
        $record->load(['company', 'details']);

        return $this->buildSnapshot(
            $record->company?->name,
            $record->year,
            $record->week,
            $record->week_start_date,
            $record->week_end_date,
            $record->commission_percentage,
            $record->details->map(static fn ($detail): array => $detail->toArray())->all(),
        );
    }

    /**
     * Build the canonical snapshot that the validated replacement payload would persist.
     *
     * @param  array<string, mixed>  $record
     * @param  list<array<string, mixed>>  $details
     * @return array<string, string|null>
     */
    public function snapshotFromValidated(array $record, array $details, ?string $companyName): array
    {
        return $this->buildSnapshot(
            $companyName,
            $record['year'] ?? null,
            $record['week'] ?? null,
            $record['week_start_date'] ?? null,
            $record['week_end_date'] ?? null,
            $record['commission_percentage'] ?? null,
            $details,
        );
    }

    /**
     * Compare two canonical snapshots and return the field-level changes.
     *
     * @param  array<string, string|null>  $previous
     * @param  array<string, string|null>  $current
     * @return list<array{field_name: string, previous_value: string|null, new_value: string|null}>
     */
    public function diff(array $previous, array $current): array
    {
        return SnapshotDiff::diff($previous, $current);
    }

    /**
     * Write one History row per changed field.
     *
     * @param  array<string, string|null>  $previous
     * @param  array<string, string|null>  $current
     * @return list<array{field_name: string, previous_value: string|null, new_value: string|null}>
     */
    public function writeFieldChanges(
        Record $record,
        array $previous,
        array $current,
        int $userId,
        string $reason,
    ): array {
        $changes = $this->diff($previous, $current);

        foreach ($changes as $change) {
            $record->histories()->create([
                'user_id' => $userId,
                'field_name' => $change['field_name'],
                'previous_value' => $change['previous_value'],
                'new_value' => $change['new_value'],
                'reason' => $reason,
            ]);
        }

        return $changes;
    }

    public function writeRecordCreated(
        Record $record,
        int $userId,
        RecordStatus $newStatus,
        string $reason,
    ): History {
        return $record->histories()->create([
            'user_id' => $userId,
            'field_name' => 'record_created',
            'previous_value' => null,
            'new_value' => $newStatus->value,
            'reason' => $reason,
        ]);
    }

    public function writeRecordUpdated(Record $record, int $userId, string $reason): History
    {
        return $record->histories()->create([
            'user_id' => $userId,
            'field_name' => 'record_updated',
            'previous_value' => null,
            'new_value' => null,
            'reason' => $reason,
        ]);
    }

    public function writeStatusChange(
        Record $record,
        RecordStatus $previousStatus,
        RecordStatus $newStatus,
        int $userId,
        string $reason,
    ): History {
        return $record->histories()->create([
            'user_id' => $userId,
            'field_name' => 'status',
            'previous_value' => $previousStatus->value,
            'new_value' => $newStatus->value,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  iterable<array<string, mixed>>  $details
     * @return array<string, string|null>
     */
    private function buildSnapshot(
        ?string $companyName,
        mixed $year,
        mixed $week,
        mixed $weekStartDate,
        mixed $weekEndDate,
        mixed $commissionPercentage,
        iterable $details,
    ): array {
        $snapshot = [
            'company' => CanonicalValue::string($companyName),
            'year' => CanonicalValue::integer($year),
            'week' => CanonicalValue::integer($week),
            'week_start_date' => CanonicalValue::date($weekStartDate),
            'week_end_date' => CanonicalValue::date($weekEndDate),
            'commission_percentage' => CanonicalValue::decimal($commissionPercentage),
        ];

        foreach ($details as $detail) {
            $order = (int) $detail['order'];
            $snapshot["details[{$order}].order"] = CanonicalValue::integer($detail['order']);

            foreach (self::DETAIL_TEXT_FIELDS as $field) {
                $snapshot["details[{$order}].{$field}"] = CanonicalValue::string($detail[$field] ?? null);
            }

            foreach (self::DETAIL_DECIMAL_FIELDS as $field) {
                $snapshot["details[{$order}].{$field}"] = CanonicalValue::decimal($detail[$field] ?? null);
            }
        }

        return $snapshot;
    }
}
