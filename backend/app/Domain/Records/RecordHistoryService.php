<?php

namespace App\Domain\Records;

use App\Enums\RecordStatus;
use App\Models\History;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class RecordHistoryService
{
    private const DETAIL_TEXT_FIELDS = [
        'affiliation',
        'branch',
    ];

    private const DETAIL_DECIMAL_FIELDS = [
        'income',
        'commission_percentage',
        'commission',
        'return_amount',
    ];

    /**
     * Build a canonical snapshot of the record and its ordered details.
     *
     * @return array<string, string|null>
     */
    public function snapshot(Record $record): array
    {
        $record->load(['company', 'details']);

        $snapshot = [
            'company' => $this->canonicalString($record->company?->name),
            'year' => $this->canonicalInteger($record->year),
            'week' => $this->canonicalInteger($record->week),
            'week_start_date' => $this->canonicalDate($record->week_start_date),
            'week_end_date' => $this->canonicalDate($record->week_end_date),
        ];

        foreach ($record->details as $detail) {
            $order = (int) $detail->order;
            $snapshot["details[{$order}].order"] = $this->canonicalInteger($detail->order);

            foreach (self::DETAIL_TEXT_FIELDS as $field) {
                $snapshot["details[{$order}].{$field}"] = $this->canonicalString($detail->{$field});
            }

            foreach (self::DETAIL_DECIMAL_FIELDS as $field) {
                $snapshot["details[{$order}].{$field}"] = $this->canonicalDecimal($detail->{$field});
            }
        }

        return $snapshot;
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
        $fields = array_keys($previous + $current);
        $changes = [];

        foreach ($fields as $field) {
            $previousValue = $previous[$field] ?? null;
            $newValue = $current[$field] ?? null;

            if ($previousValue === $newValue) {
                continue;
            }

            $changes[] = [
                'field_name' => $field,
                'previous_value' => $previousValue,
                'new_value' => $newValue,
            ];
        }

        return $changes;
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

    private function canonicalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function canonicalInteger(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) (int) $value;
    }

    private function canonicalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return CarbonImmutable::parse((string) $value)->toDateString();
    }

    private function canonicalDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }
}
