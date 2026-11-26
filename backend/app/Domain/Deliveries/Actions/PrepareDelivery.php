<?php

namespace App\Domain\Deliveries\Actions;

use App\Domain\Deliveries\CashBreakdown;
use App\Domain\Deliveries\DeliveryHistoryService;
use App\Domain\Deliveries\DeliveryInputNormalizer;
use App\Domain\Deliveries\DeliveryReturnPolicy;
use App\Domain\Deliveries\DeliverySplit;
use App\Domain\Deliveries\Exceptions\DeliveryPreparationBlocked;
use App\Enums\RecordStatus;
use App\Models\Delivery;
use App\Models\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or replace the delivery of a CONFIRMED record, snapshotting its current
 * return and splitting it between transfer and cash (SPEC 28). The breakdown
 * reconciles against the cash to deliver. An unchanged resubmission writes nothing.
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
            $requestedCashAmount = (string) $this->normalizer->canonicalAmount($validated['requested_cash_amount']);

            if ($this->normalizer->money($requestedCashAmount)->compare($this->normalizer->money($currentReturnAmount)) > 0) {
                throw DeliveryPreparationBlocked::cashRequestExceedsReturn($requestedCashAmount, $currentReturnAmount);
            }

            $split = DeliverySplit::calculate(
                $currentReturnAmount,
                $requestedCashAmount,
                (string) $validated['cash_commission_percentage'],
            );

            // Without cash to deliver the breakdown must be empty: the Word would not show that cash.
            if (! $split->hasCash() && $denominations !== []) {
                throw ValidationException::withMessages([
                    'denominations' => ['The cash breakdown must be empty when there is no cash to deliver.'],
                ]);
            }

            // An empty breakdown stays valid only when there is no cash to deliver.
            $mismatch = $this->cash->preparationCashShortfall($split->cashAmount, $denominations);

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
                ...$split->attributes(),
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
                $attributes['requested_cash_amount'],
                $attributes['cash_commission_percentage'],
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
