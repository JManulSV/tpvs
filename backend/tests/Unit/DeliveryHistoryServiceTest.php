<?php

namespace Tests\Unit;

use App\Domain\Deliveries\DeliveryHistoryService;
use App\Models\Delivery;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_canonicalizes_delivery_fields_and_sorts_denominations(): void
    {
        $record = Record::factory()->create();
        $delivery = Delivery::factory()->for($record)->create([
            'delivery_date' => '2026-09-18',
            'delivered_by_name' => '  Maria Lopez  ',
            'received_by_name' => null,
            'prepared_return_amount' => '9800',
        ]);
        $delivery->cashDenominations()->createMany([
            ['value' => '100.00', 'quantity' => 3],
            ['value' => '1000.00', 'quantity' => 5],
            ['value' => '500.00', 'quantity' => 5],
            ['value' => '200.00', 'quantity' => 10],
        ]);

        $snapshot = $this->service()->snapshot($delivery);

        $this->assertSame([
            'delivery_date' => '2026-09-18',
            'delivered_by_name' => 'Maria Lopez',
            'received_by_name' => null,
            'prepared_return_amount' => '9800.00',
            'denominations[1000.00]' => '5',
            'denominations[500.00]' => '5',
            'denominations[200.00]' => '10',
            'denominations[100.00]' => '3',
        ], $snapshot);
    }

    public function test_snapshot_omits_denominations_that_are_not_persisted(): void
    {
        $record = Record::factory()->create();
        $delivery = Delivery::factory()->for($record)->create([
            'prepared_return_amount' => '0.00',
        ]);

        $snapshot = $this->service()->snapshot($delivery);

        $this->assertSame([
            'delivery_date' => $delivery->delivery_date->toDateString(),
            'delivered_by_name' => $delivery->delivered_by_name,
            'received_by_name' => $delivery->received_by_name,
            'prepared_return_amount' => '0.00',
        ], $snapshot);
    }

    public function test_diff_detects_added_removed_and_changed_denominations(): void
    {
        $changes = collect($this->service()->diff(
            [
                'delivery_date' => '2026-09-18',
                'prepared_return_amount' => '9800.00',
                'denominations[1000.00]' => '5',
                'denominations[500.00]' => '5',
            ],
            [
                'delivery_date' => '2026-09-19',
                'prepared_return_amount' => '9800.00',
                'denominations[1000.00]' => '6',
                'denominations[100.00]' => '3',
            ],
        ))->keyBy('field_name');

        $this->assertSame('2026-09-18', $changes['delivery_date']['previous_value']);
        $this->assertSame('2026-09-19', $changes['delivery_date']['new_value']);
        $this->assertSame('5', $changes['denominations[1000.00]']['previous_value']);
        $this->assertSame('6', $changes['denominations[1000.00]']['new_value']);
        $this->assertSame('5', $changes['denominations[500.00]']['previous_value']);
        $this->assertNull($changes['denominations[500.00]']['new_value']);
        $this->assertNull($changes['denominations[100.00]']['previous_value']);
        $this->assertSame('3', $changes['denominations[100.00]']['new_value']);
        $this->assertArrayNotHasKey('prepared_return_amount', $changes->all());
    }

    public function test_diff_does_not_report_canonical_equivalents(): void
    {
        $this->assertSame([], $this->service()->diff(
            ['prepared_return_amount' => '9800.00', 'denominations[1000.00]' => '5'],
            ['prepared_return_amount' => '9800.00', 'denominations[1000.00]' => '5'],
        ));
    }

    public function test_write_field_changes_creates_one_history_row_per_changed_field_with_delivery_id(): void
    {
        $record = Record::factory()->create();
        $user = User::factory()->create();
        $delivery = Delivery::factory()->for($record)->create();

        $changes = $this->service()->writeFieldChanges(
            $delivery,
            [
                'delivery_date' => '2026-09-18',
                'denominations[1000.00]' => '5',
            ],
            [
                'delivery_date' => '2026-09-19',
                'denominations[1000.00]' => '6',
                'denominations[500.00]' => '2',
            ],
            $user->id,
            'Preparación de entrega actualizada',
        );

        $this->assertCount(3, $changes);
        $this->assertDatabaseCount('histories', 3);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'record_detail_id' => null,
            'delivery_id' => $delivery->id,
            'user_id' => $user->id,
            'field_name' => 'delivery_date',
            'previous_value' => '2026-09-18',
            'new_value' => '2026-09-19',
            'reason' => 'Preparación de entrega actualizada',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'record_detail_id' => null,
            'delivery_id' => $delivery->id,
            'user_id' => $user->id,
            'field_name' => 'denominations[1000.00]',
            'previous_value' => '5',
            'new_value' => '6',
            'reason' => 'Preparación de entrega actualizada',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'record_detail_id' => null,
            'delivery_id' => $delivery->id,
            'user_id' => $user->id,
            'field_name' => 'denominations[500.00]',
            'previous_value' => null,
            'new_value' => '2',
            'reason' => 'Preparación de entrega actualizada',
        ]);
    }

    public function test_creation_writes_a_row_for_every_persisted_field(): void
    {
        $record = Record::factory()->create();
        $user = User::factory()->create();
        $delivery = Delivery::factory()->for($record)->create([
            'delivery_date' => '2026-09-18',
            'delivered_by_name' => 'Maria Lopez',
            'received_by_name' => 'Juan Perez',
            'prepared_return_amount' => '9800.00',
        ]);
        $delivery->cashDenominations()->create(['value' => '1000.00', 'quantity' => 5]);

        $changes = $this->service()->writeFieldChanges(
            $delivery,
            [],
            $this->service()->snapshot($delivery),
            $user->id,
            'Preparación de entrega creada',
        );

        $this->assertCount(5, $changes);
        $this->assertDatabaseCount('histories', 5);
        $this->assertDatabaseHas('histories', [
            'delivery_id' => $delivery->id,
            'field_name' => 'delivered_by_name',
            'previous_value' => null,
            'new_value' => 'Maria Lopez',
            'reason' => 'Preparación de entrega creada',
        ]);
        $this->assertDatabaseHas('histories', [
            'delivery_id' => $delivery->id,
            'field_name' => 'denominations[1000.00]',
            'previous_value' => null,
            'new_value' => '5',
            'reason' => 'Preparación de entrega creada',
        ]);
    }

    public function test_write_field_changes_does_not_create_rows_without_differences(): void
    {
        $record = Record::factory()->create();
        $user = User::factory()->create();
        $delivery = Delivery::factory()->for($record)->create();
        $snapshot = [
            'delivery_date' => '2026-09-18',
            'denominations[1000.00]' => '5',
        ];

        $changes = $this->service()->writeFieldChanges(
            $delivery,
            $snapshot,
            $snapshot,
            $user->id,
            'Sin cambios',
        );

        $this->assertSame([], $changes);
        $this->assertDatabaseCount('histories', 0);
    }

    private function service(): DeliveryHistoryService
    {
        return app(DeliveryHistoryService::class);
    }
}
