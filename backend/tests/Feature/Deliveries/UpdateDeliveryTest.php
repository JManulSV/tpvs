<?php

namespace Tests\Feature\Deliveries;

use App\Enums\UserRole;
use App\Models\Delivery;
use App\Models\History;
use App\Models\RecordDetail;
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
        $this->assertDatabaseCount('histories', 8);
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

        RecordDetail::query()
            ->where('record_id', $record->id)
            ->update(['return_amount' => '10000.00']);

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
            ->assertJsonCount(8, 'data')
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
        $this->assertDatabaseCount('histories', 8);
    }
}
