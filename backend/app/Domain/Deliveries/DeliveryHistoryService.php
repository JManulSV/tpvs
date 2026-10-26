<?php

namespace App\Domain\Deliveries;

use App\Models\Delivery;

class DeliveryHistoryService
{
    public function __construct(private readonly DeliveryValidator $validator) {}

    /**
     * Build a canonical snapshot of the delivery and its cash denominations.
     *
     * @return array<string, string|null>
     */
    public function snapshot(Delivery $delivery): array
    {
        $delivery->load('cashDenominations');

        $snapshot = [
            'delivery_date' => $this->validator->canonicalDate($delivery->delivery_date),
            'delivered_by_name' => $this->validator->canonicalName($delivery->delivered_by_name),
            'received_by_name' => $this->validator->canonicalName($delivery->received_by_name),
            'prepared_return_amount' => $this->validator->canonicalAmount($delivery->prepared_return_amount),
        ];

        $denominations = $delivery->cashDenominations
            ->sortByDesc(static fn ($denomination): float => (float) $denomination->value)
            ->values();

        foreach ($denominations as $denomination) {
            $value = $this->validator->canonicalAmount($denomination->value);
            $snapshot["denominations[{$value}]"] = $this->validator->canonicalQuantity($denomination->quantity);
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
     * Write one History row per changed field, linked to the delivery.
     *
     * @param  array<string, string|null>  $previous
     * @param  array<string, string|null>  $current
     * @return list<array{field_name: string, previous_value: string|null, new_value: string|null}>
     */
    public function writeFieldChanges(
        Delivery $delivery,
        array $previous,
        array $current,
        int $userId,
        string $reason,
    ): array {
        $changes = $this->diff($previous, $current);

        foreach ($changes as $change) {
            $delivery->histories()->create([
                'record_id' => $delivery->record_id,
                'user_id' => $userId,
                'field_name' => $change['field_name'],
                'previous_value' => $change['previous_value'],
                'new_value' => $change['new_value'],
                'reason' => $reason,
            ]);
        }

        return $changes;
    }
}
