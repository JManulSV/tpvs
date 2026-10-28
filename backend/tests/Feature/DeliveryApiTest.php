<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Delivery;
use App\Models\History;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_endpoints_require_authentication(): void
    {
        $record = $this->createRecordWithReturn('9800.00');

        $this->getJson('/api/records/'.$record->id.'/delivery')->assertUnauthorized();
        $this->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())->assertUnauthorized();
    }

    public function test_editors_and_viewers_can_read_a_null_delivery_in_any_record_state(): void
    {
        $record = $this->createRecordWithReturn('9800.00', RecordStatus::NEEDS_REVIEW);

        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')
                ->getJson('/api/records/'.$record->id.'/delivery')
                ->assertOk()
                ->assertExactJson(['data' => null]);
        }
    }

    public function test_delivery_read_returns_not_found_for_an_unknown_record(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records/999999/delivery')
            ->assertNotFound();
    }

    public function test_viewers_cannot_prepare_a_delivery(): void
    {
        $record = $this->createRecordWithReturn('9800.00');
        $viewer = User::factory()->create(['role' => UserRole::VIEWER]);

        $this->actingAs($viewer, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_editor_can_create_a_delivery_with_canonical_history(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        $response = $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload());

        $response->assertOk()
            ->assertJsonPath('data.record_id', $record->id)
            ->assertJsonPath('data.delivery_date', '2026-09-18')
            ->assertJsonPath('data.delivered_by_name', 'Maria Lopez')
            ->assertJsonPath('data.received_by_name', 'Juan Perez')
            ->assertJsonPath('data.prepared_return_amount', '9800.00')
            ->assertJsonPath('data.current_return_amount', '9800.00')
            ->assertJsonPath('data.is_stale', false)
            ->assertJsonPath('data.denominations.0.value', '1000.00')
            ->assertJsonPath('data.denominations.0.quantity', 5)
            ->assertJsonPath('data.denominations.1.value', '500.00')
            ->assertJsonPath('data.denominations.2.value', '200.00')
            ->assertJsonPath('data.denominations.3.value', '100.00');

        $delivery = Delivery::query()->sole();

        $this->assertDatabaseCount('deliveries', 1);
        $this->assertSame($record->id, $delivery->record_id);
        $this->assertSame('9800.00', $delivery->prepared_return_amount);
        $this->assertDatabaseCount('delivery_cash_denominations', 4);
        $this->assertDatabaseCount('histories', 8);

        foreach ([
            ['field_name' => 'delivery_date', 'previous_value' => null, 'new_value' => '2026-09-18'],
            ['field_name' => 'delivered_by_name', 'previous_value' => null, 'new_value' => 'Maria Lopez'],
            ['field_name' => 'received_by_name', 'previous_value' => null, 'new_value' => 'Juan Perez'],
            ['field_name' => 'prepared_return_amount', 'previous_value' => null, 'new_value' => '9800.00'],
            ['field_name' => 'denominations[1000.00]', 'previous_value' => null, 'new_value' => '5'],
            ['field_name' => 'denominations[500.00]', 'previous_value' => null, 'new_value' => '5'],
            ['field_name' => 'denominations[200.00]', 'previous_value' => null, 'new_value' => '10'],
            ['field_name' => 'denominations[100.00]', 'previous_value' => null, 'new_value' => '3'],
        ] as $expected) {
            $this->assertDatabaseHas('histories', [
                'record_id' => $record->id,
                'record_detail_id' => null,
                'delivery_id' => $delivery->id,
                'user_id' => $editor->id,
                'reason' => 'Preparación de entrega creada',
            ] + $expected);
        }
    }

    public function test_only_confirmed_records_can_be_prepared(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);

        foreach ([
            RecordStatus::PENDING,
            RecordStatus::PROCESSING,
            RecordStatus::NEEDS_REVIEW,
            RecordStatus::ERROR,
            RecordStatus::CANCELED,
        ] as $status) {
            $record = $this->createRecordWithReturn('9800.00', $status);

            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload())
                ->assertStatus(409)
                ->assertJsonPath('code', 'DELIVERY_PREPARATION_BLOCKED')
                ->assertJsonPath('details.reason', 'INVALID_RECORD_STATE');
        }

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_cash_mismatch_is_rejected_without_partial_writes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'denominations' => [
                    ['value' => '1000.00', 'quantity' => 5],
                ],
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'DELIVERY_PREPARATION_BLOCKED')
            ->assertJsonPath('details.reason', 'CASH_MISMATCH')
            ->assertJsonPath('details.expected_amount', '9800.00')
            ->assertJsonPath('details.cash_amount', '5000.00')
            ->assertJsonPath('details.difference', '-4800.00');

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_zero_quantities_are_ignored_and_empty_breakdown_requires_zero_return(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $zeroRecord = $this->createRecordWithReturn('0.00');
        $zeroDenominations = array_map(
            static fn (string $value): array => ['value' => $value, 'quantity' => 0],
            ['1000.00', '500.00', '200.00', '100.00', '50.00', '20.00', '10.00', '5.00', '2.00', '1.00', '0.50'],
        );

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$zeroRecord->id.'/delivery', $this->validPayload([
                'denominations' => $zeroDenominations,
            ]))
            ->assertOk()
            ->assertJsonPath('data.prepared_return_amount', '0.00')
            ->assertJsonPath('data.current_return_amount', '0.00')
            ->assertJsonPath('data.denominations', []);

        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 4);

        $nonZeroRecord = $this->createRecordWithReturn('9800.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$nonZeroRecord->id.'/delivery', $this->validPayload([
                'denominations' => [],
            ]))
            ->assertStatus(409)
            ->assertJsonPath('details.reason', 'CASH_MISMATCH')
            ->assertJsonPath('details.cash_amount', '0.00')
            ->assertJsonPath('details.difference', '-9800.00');

        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_zero_quantity_duplicates_are_discarded_before_validation(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('1000.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'denominations' => [
                    ['value' => '1000.00', 'quantity' => 1],
                    ['value' => '1000.00', 'quantity' => 0],
                    ['value' => '500.00', 'quantity' => 0],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.denominations', [
                ['value' => '1000.00', 'quantity' => 1],
            ]);

        $this->assertDatabaseCount('delivery_cash_denominations', 1);
    }

    public function test_invalid_denominations_duplicates_and_invalid_shape_are_rejected(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        $invalidPayloads = [
            'non official value' => ['denominations' => [['value' => '25.00', 'quantity' => 1]]],
            'negative quantity' => ['denominations' => [['value' => '1000.00', 'quantity' => -1]]],
            'non integer quantity' => ['denominations' => [['value' => '1000.00', 'quantity' => 1.5]]],
            'duplicate positive values' => ['denominations' => [
                ['value' => '1000.00', 'quantity' => 1],
                ['value' => '1000', 'quantity' => 1],
            ]],
            'missing delivered name' => ['delivered_by_name' => ''],
            'missing date' => ['delivery_date' => null],
            'invalid date' => ['delivery_date' => 'not-a-date'],
        ];

        foreach ($invalidPayloads as $case => $overrides) {
            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload($overrides))
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_client_cannot_send_derived_or_controlled_fields(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        foreach (['prepared_return_amount', 'record_id', 'status', 'source', 'reason', 'total', 'cash_total'] as $field) {
            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                    $field => 'anything',
                ]))
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('deliveries', 0);
    }

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

    private function createRecordWithReturn(
        string $returnAmount,
        RecordStatus $status = RecordStatus::CONFIRMED,
    ): Record {
        $record = Record::factory()->create(['status' => $status]);

        $record->details()->create([
            'affiliation' => 'AFF-12345',
            'branch' => 'Guadalajara',
            'income' => '10000.00',
            'commission_percentage' => '2.00',
            'commission' => '200.00',
            'return_amount' => $returnAmount,
            'order' => 1,
        ]);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
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
