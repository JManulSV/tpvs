<?php

namespace App\Domain\Deliveries\Actions;

use App\Domain\Deliveries\CashBreakdown;
use App\Domain\Deliveries\DeliveryHistoryService;
use App\Domain\Deliveries\DeliveryInputNormalizer;
use App\Domain\Deliveries\DeliveryReturnPolicy;
use App\Domain\Deliveries\Exceptions\DeliveryPreparationBlocked;
use App\Enums\RecordStatus;
use App\Models\Delivery;
use App\Models\Record;
use Illuminate\Support\Facades\DB;

/**
 * Create or replace the delivery of a CONFIRMED record, snapshotting its current
 * return. An unchanged resubmission writes nothing.
 */
class PrepareDelivery
{
    public function __construct(
        private readonly DeliveryInputNormalizer $normalizer,
        private readonly DeliveryReturnPolicy $returnPolicy,
        private readonly CashBreakdown $cash,
        private readonly DeliveryHistoryService $history,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(Record $record, array $validated, int $userId): Delivery
    {
        $denominations = $this->normalizer->canonicalizeDenominations($validated['denominations']);

        return DB::transaction(function () use ($record, $validated, $denominations, $userId): Delivery {
            $record = Record::findLockedOrFail($record->getKey());

            if ($record->status !== RecordStatus::CONFIRMED) {
                throw DeliveryPreparationBlocked::invalidRecordState();
            }

            $delivery = Delivery::query()
                ->where('record_id', $record->getKey())
                ->lockForUpdate()
                ->first();

            $currentReturnAmount = $this->returnPolicy->currentReturnAmount($record);
            $mismatch = $this->cash->preparationCashShortfall($currentReturnAmount, $denominations);

            if ($mismatch !== null) {
                throw DeliveryPreparationBlocked::cashMismatch(
                    $mismatch['expected_amount'],
                    $mismatch['cash_amount'],
                    $mismatch['difference'],
                );
            }

            $attributes = [
                'delivery_date' => $this->normalizer->canonicalDate($validated['delivery_date']),
                'delivered_by_name' => $this->normalizer->canonicalName($validated['delivered_by_name']),
                'received_by_name' => $this->normalizer->canonicalName($validated['received_by_name'] ?? null),
                'prepared_return_amount' => $currentReturnAmount,
            ];

            if ($delivery === null) {
                $delivery = $record->delivery()->create($attributes);
                $delivery->cashDenominations()->createMany($denominations);

                $this->history->writeFieldChanges(
                    $delivery,
                    [],
                    $this->history->snapshot($delivery),
                    $userId,
                    'Preparación de entrega creada',
                );

                return $delivery;
            }

            $before = $this->history->snapshot($delivery);
            $after = $this->history->snapshotFromValues(
                $attributes['delivery_date'],
                $attributes['delivered_by_name'],
                $attributes['received_by_name'],
                $attributes['prepared_return_amount'],
                $denominations,
            );

            if ($this->history->diff($before, $after) === []) {
                return $delivery;
            }

            $delivery->update($attributes);
            $delivery->cashDenominations()->delete();
            $delivery->cashDenominations()->createMany($denominations);

            $this->history->writeFieldChanges(
                $delivery,
                $before,
                $this->history->snapshot($delivery),
                $userId,
                'Preparación de entrega actualizada',
            );

            return $delivery;
        });
    }
}
