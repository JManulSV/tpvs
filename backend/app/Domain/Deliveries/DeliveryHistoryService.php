<?php

namespace App\Domain\Deliveries;

use App\Domain\Shared\History\CanonicalValue;
use App\Domain\Shared\History\SnapshotDiff;
use App\Models\Delivery;
use App\Models\History;

class DeliveryHistoryService
{
    public function __construct(private readonly DeliveryInputNormalizer $normalizer) {}

    /**
     * Build a canonical snapshot of the delivery and its cash denominations.
     *
     * @return array<string, string|null>
     */
    public function snapshot(Delivery $delivery): array
    {
        $delivery->load('cashDenominations');

        return $this->buildSnapshot(
            $delivery->delivery_date,
            $delivery->delivered_by_name,
            $delivery->received_by_name,
            $delivery->prepared_return_amount,
            $delivery->requested_cash_amount,
            $delivery->cash_commission_percentage,
            $delivery->cashDenominations->map(static fn ($denomination): array => [
                'value' => $denomination->value,
                'quantity' => $denomination->quantity,
            ])->all(),
        );
    }

    /**
     * Build the canonical snapshot that the validated replacement payload would persist.
     *
     * @param  iterable<array{value: string, quantity: int}>  $denominations
     * @return array<string, string|null>
     */
    public function snapshotFromValues(
        mixed $deliveryDate,
        mixed $deliveredByName,
        mixed $receivedByName,
        mixed $preparedReturnAmount,
        mixed $requestedCashAmount,
        mixed $cashCommissionPercentage,
        iterable $denominations,
    ): array {
        return $this->buildSnapshot(
            $deliveryDate,
            $deliveredByName,
            $receivedByName,
            $preparedReturnAmount,
            $requestedCashAmount,
            $cashCommissionPercentage,
            $denominations,
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

    /**
     * Record that the Word document was generated; no field values change.
     */
    public function writeDocumentGenerated(Delivery $delivery, int $userId): History
    {
        return $delivery->histories()->create([
            'record_id' => $delivery->record_id,
            'user_id' => $userId,
            'field_name' => 'document_generated',
            'previous_value' => null,
            'new_value' => null,
            'reason' => 'Documento Word generado',
        ]);
    }

    /**
     * @param  iterable<array{value: string, quantity: int}>  $denominations
     * @return array<string, string|null>
     */
    private function buildSnapshot(
        mixed $deliveryDate,
        mixed $deliveredByName,
        mixed $receivedByName,
        mixed $preparedReturnAmount,
        mixed $requestedCashAmount,
        mixed $cashCommissionPercentage,
        iterable $denominations,
    ): array {
        $snapshot = [
            'delivery_date' => CanonicalValue::date($deliveryDate),
            'delivered_by_name' => CanonicalValue::string($deliveredByName),
            'received_by_name' => CanonicalValue::string($receivedByName),
            'prepared_return_amount' => $this->normalizer->canonicalAmount($preparedReturnAmount),
            // The split inputs (SPEC 28). transfer_amount, cash_commission and cash_amount are derived and not audited.
            'requested_cash_amount' => $this->normalizer->canonicalAmount($requestedCashAmount),
            'cash_commission_percentage' => $this->normalizer->canonicalAmount($cashCommissionPercentage),
        ];

        $ordered = [];

        foreach ($denominations as $denomination) {
            $value = $this->normalizer->canonicalAmount($denomination['value']);
            $ordered[$value] = CanonicalValue::integer($denomination['quantity']);
        }

        uksort(
            $ordered,
            static fn (string $left, string $right): int => (float) $right <=> (float) $left,
        );

        foreach ($ordered as $value => $quantity) {
            $snapshot["denominations[{$value}]"] = $quantity;
        }

        return $snapshot;
    }
}
