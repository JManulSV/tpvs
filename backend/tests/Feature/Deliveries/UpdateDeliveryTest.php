<?php

namespace Tests\Feature\Deliveries;

use App\Enums\UserRole;
use App\Models\Delivery;
use App\Models\History;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Concerns\BuildsDeliveryPayloads;
use Tests\TestCase;

class UpdateDeliveryTest extends TestCase
{
    use BuildsDeliveryPayloads, RefreshDatabase;

    public function test_second_preparation_updates_the_same_delivery_and_records_changes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        $deliveryId = $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())
            ->assertOk()
            ->json('data.id');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'delivery_date' => '2026-09-19',
                'delivered_by_name' => 'Maria Lopez Actualizada',
                'received_by_name' => '   ',
                'denominations' => [
                    ['value' => '1000.00', 'quantity' => 9],
                    ['value' => '500.00', 'quantity' => 1],
                    ['value' => '200.00', 'quantity' => 1],
                    ['value' => '100.00', 'quantity' => 1],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.id', $deliveryId)
            ->assertJsonPath('data.delivery_date', '2026-09-19')
            ->assertJsonPath('data.delivered_by_name', 'Maria Lopez Actualizada')
            ->assertJsonPath('data.received_by_name', null)
            ->assertJsonPath('data.prepared_return_amount', '9800.00')
            ->assertJsonPath('data.is_stale', false);

        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'record_id' => $record->id,
            'received_by_name' => null,
            'prepared_return_amount' => '9800.00',
        ]);
        $this->assertDatabaseCount('delivery_cash_denominations', 4);
        $this->assertDatabaseHas('delivery_cash_denominations', [
            'delivery_id' => $deliveryId,
            'value' => '1000.00',
            'quantity' => 9,
        ]);

        foreach ([
            ['field_name' => 'delivery_date', 'previous_value' => '2026-09-18', 'new_value' => '2026-09-19'],
            ['field_name' => 'delivered_by_name', 'previous_value' => 'Maria Lopez', 'new_value' => 'Maria Lopez Actualizada'],
            ['field_name' => 'received_by_name', 'previous_value' => 'Juan Perez', 'new_value' => null],
            ['field_name' => 'denominations[1000.00]', 'previous_value' => '5', 'new_value' => '9'],
            ['field_name' => 'denominations[500.00]', 'previous_value' => '5', 'new_value' => '1'],
        ] as $expected) {
            $this->assertDatabaseHas('histories', [
                'record_id' => $record->id,
                'delivery_id' => $deliveryId,
                'user_id' => $editor->id,
                'reason' => 'Preparación de entrega actualizada',
            ] + $expected);
        }
    }

    public function test_identical_preparation_is_a_noop_without_history_or_timestamp_changes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        $delivery = $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())
            ->assertOk()
            ->json('data');

        $updatedAt = Delivery::query()->findOrFail($delivery['id'])->updated_at->toISOString();

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'delivered_by_name' => '  Maria Lopez  ',
                'denominations' => [
                    ['value' => '100', 'quantity' => 3],
                    ['value' => '200.0', 'quantity' => 10],
                    ['value' => '500', 'quantity' => 5],
                    ['value' => '1000.00', 'quantity' => 5],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.id', $delivery['id']);

        $this->assertSame(
            $updatedAt,
            Delivery::query()->findOrFail($delivery['id'])->updated_at->toISOString(),
        );
        $this->assertDatabaseCount('histories', 10);
        $this->assertDatabaseCount('delivery_cash_denominations', 4);
    }

    public function test_stale_delivery_is_reported_and_can_be_updated_in_the_same_row(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        $deliveryId = $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())
            ->assertOk()
            ->json('data.id');

        $record->update(['return_amount' => '10000.00']);

        $this->actingAs($editor, 'web')
            ->getJson('/api/records/'.$record->id.'/delivery')
            ->assertOk()
            ->assertJsonPath('data.id', $deliveryId)
            ->assertJsonPath('data.prepared_return_amount', '9800.00')
            ->assertJsonPath('data.current_return_amount', '10000.00')
            ->assertJsonPath('data.is_stale', true);

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'denominations' => [
                    ['value' => '1000.00', 'quantity' => 10],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.id', $deliveryId)
            ->assertJsonPath('data.prepared_return_amount', '10000.00')
            ->assertJsonPath('data.current_return_amount', '10000.00')
            ->assertJsonPath('data.is_stale', false);

        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'prepared_return_amount' => '10000.00',
        ]);
        $this->assertDatabaseHas('histories', [
            'delivery_id' => $deliveryId,
            'field_name' => 'prepared_return_amount',
            'previous_value' => '9800.00',
            'new_value' => '10000.00',
            'reason' => 'Preparación de entrega actualizada',
        ]);
    }

    public function test_delivery_events_are_visible_in_the_existing_record_history(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $viewer = User::factory()->create(['role' => UserRole::VIEWER]);
        $record = $this->createRecordWithReturn('9800.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())
            ->assertOk();

        $this->actingAs($viewer, 'web')
            ->getJson('/api/records/'.$record->id.'/history')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonFragment([
                'field_name' => 'delivery_date',
                'previous_value' => null,
                'new_value' => '2026-09-18',
                'reason' => 'Preparación de entrega creada',
            ])
            ->assertJsonFragment([
                'field_name' => 'denominations[1000.00]',
                'previous_value' => null,
                'new_value' => '5',
                'reason' => 'Preparación de entrega creada',
            ]);
    }

    public function test_failed_history_write_rolls_back_delivery_creation(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        Event::listen('eloquent.creating: '.History::class, static function (): void {
            throw new \RuntimeException('Simulated delivery history failure.');
        });

        try {
            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())
                ->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.History::class);
        }

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_failed_history_write_rolls_back_delivery_update(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        $deliveryId = $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())
            ->assertOk()
            ->json('data.id');

        Event::listen('eloquent.creating: '.History::class, static function (): void {
            throw new \RuntimeException('Simulated delivery history failure.');
        });

        try {
            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                    'delivered_by_name' => 'Rollback Name',
                ]))
                ->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.History::class);
        }

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'delivered_by_name' => 'Maria Lopez',
        ]);
        $this->assertDatabaseCount('delivery_cash_denominations', 4);
        $this->assertDatabaseCount('histories', 10);
    }

    public function test_changing_the_split_audits_only_its_inputs(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('200.00');
        $allCash = [
            'requested_cash_amount' => '200.00',
            'cash_commission_percentage' => '0.00',
            'denominations' => [['value' => '200.00', 'quantity' => 1]],
        ];

        $deliveryId = $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload($allCash))
            ->assertOk()
            ->json('data.id');

        $historiesAfterCreation = History::query()->count();

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '100.00',
                'cash_commission_percentage' => '10.00',
                'denominations' => [['value' => '50.00', 'quantity' => 1], ['value' => '20.00', 'quantity' => 2]],
            ]))
            ->assertOk();

        foreach ([
            ['field_name' => 'requested_cash_amount', 'previous_value' => '200.00', 'new_value' => '100.00'],
            ['field_name' => 'cash_commission_percentage', 'previous_value' => '0.00', 'new_value' => '10.00'],
        ] as $expected) {
            $this->assertDatabaseHas('histories', [
                'delivery_id' => $deliveryId,
                'reason' => 'Preparación de entrega actualizada',
            ] + $expected);
        }

        foreach (['transfer_amount', 'cash_commission', 'cash_amount'] as $derived) {
            $this->assertDatabaseMissing('histories', ['field_name' => $derived]);
        }

        // 2 split inputs + denominations: 200 removed, 50 and 20 added.
        $this->assertSame($historiesAfterCreation + 5, History::query()->count());
    }

    public function test_changing_only_the_cash_commission_percentage_is_not_a_noop(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())
            ->assertOk();

        // 9800.00 × 0.01% = 0.98, so the 9800.00 breakdown is 0.98 over the 9799.02 to deliver.
        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'cash_commission_percentage' => '0.01',
            ]))
            ->assertOk()
            ->assertJsonPath('data.cash_commission', '0.98')
            ->assertJsonPath('data.cash_amount', '9799.02');

        $this->assertDatabaseHas('histories', [
            'field_name' => 'cash_commission_percentage',
            'previous_value' => '0.00',
            'new_value' => '0.01',
        ]);
        $this->assertDatabaseCount('histories', 11);
    }

    public function test_stale_delivery_keeps_its_split_and_the_transfer_absorbs_the_new_return(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('200.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '100.00',
                'cash_commission_percentage' => '10.00',
                'denominations' => [['value' => '50.00', 'quantity' => 1], ['value' => '20.00', 'quantity' => 2]],
            ]))
            ->assertOk();

        $record->update(['return_amount' => '250.00']);

        // The stored split is read against the stored official return.
        $this->actingAs($editor, 'web')
            ->getJson('/api/records/'.$record->id.'/delivery')
            ->assertOk()
            ->assertJsonPath('data.is_stale', true)
            ->assertJsonPath('data.prepared_return_amount', '200.00')
            ->assertJsonPath('data.current_return_amount', '250.00')
            ->assertJsonPath('data.requested_cash_amount', '100.00')
            ->assertJsonPath('data.transfer_amount', '100.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '100.00',
                'cash_commission_percentage' => '10.00',
                'denominations' => [['value' => '50.00', 'quantity' => 1], ['value' => '20.00', 'quantity' => 2]],
            ]))
            ->assertOk()
            ->assertJsonPath('data.is_stale', false)
            ->assertJsonPath('data.prepared_return_amount', '250.00')
            ->assertJsonPath('data.transfer_amount', '150.00')
            ->assertJsonPath('data.cash_amount', '90.00');
    }
}
