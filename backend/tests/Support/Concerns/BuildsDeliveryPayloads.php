<?php

namespace Tests\Support\Concerns;

use App\Enums\RecordStatus;
use App\Models\Delivery;
use App\Models\Record;

/** A Record with a given return and the API payload that prepares its delivery. */
trait BuildsDeliveryPayloads
{
    protected function createRecordWithReturn(
        string $returnAmount,
        RecordStatus $status = RecordStatus::CONFIRMED,
    ): Record {
        $record = Record::factory()->create([
            'status' => $status,
            'commission_percentage' => '2.00',
            'commission' => '200.00',
            'return_amount' => $returnAmount,
        ]);

        $record->details()->create([
            'affiliation' => 'AFF-12345',
            'bank' => 'LUUM',
            'income' => '10000.00',
            'order' => 1,
        ]);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'delivery_date' => '2026-09-18',
            'delivered_by_name' => 'Maria Lopez',
            'received_by_name' => 'Juan Perez',
            'denominations' => [
                ['value' => '1000.00', 'quantity' => 5],
                ['value' => '500.00', 'quantity' => 5],
                ['value' => '200.00', 'quantity' => 10],
                ['value' => '100.00', 'quantity' => 3],
            ],
        ], $overrides);
    }
}
