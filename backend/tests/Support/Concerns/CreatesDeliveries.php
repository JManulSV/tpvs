<?php

namespace Tests\Support\Concerns;

use App\Enums\RecordStatus;
use App\Models\Delivery;
use App\Models\DeliveryCashDenomination;
use App\Models\Record;
use App\Models\RecordDetail;

trait CreatesDeliveries
{
    /**
     * A Delivery for a CONFIRMED Record with one 1000.00 movement at 8.00%
     * (80.00 commission, 920.00 return) and cash of 500×1, 200×2 and 10×2 = 920.00.
     *
     * @param  array<string, mixed>  $recordAttributes
     * @param  array<string, mixed>  $deliveryAttributes
     * @param  list<array{0: string, 1: int}>|null  $denominations  [value, quantity] pairs
     */
    protected function createDelivery(
        array $recordAttributes = [],
        array $deliveryAttributes = [],
        ?array $denominations = null,
    ): Delivery {
        $record = Record::factory()->create(array_merge([
            'status' => RecordStatus::CONFIRMED,
            'commission_percentage' => '8.00',
            'commission' => '80.00',
            'return_amount' => '920.00',
        ], $recordAttributes));

        RecordDetail::factory()->create([
            'record_id' => $record->getKey(),
            'income' => '1000.00',
            'order' => 1,
        ]);

        $delivery = Delivery::factory()->create(array_merge([
            'record_id' => $record->getKey(),
            'prepared_return_amount' => '920.00',
        ], $deliveryAttributes));

        foreach ($denominations ?? [['500.00', 1], ['200.00', 2], ['10.00', 2]] as [$value, $quantity]) {
            DeliveryCashDenomination::factory()->create([
                'delivery_id' => $delivery->getKey(),
                'value' => $value,
                'quantity' => $quantity,
            ]);
        }

        return $delivery;
    }
}
