<?php

namespace Tests\Feature\Deliveries;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\BuildsDeliveryPayloads;
use Tests\TestCase;

class PrepareDeliveryTest extends TestCase
{
    use BuildsDeliveryPayloads, RefreshDatabase;

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
            ->assertJsonPath('data.requested_cash_amount', '9800.00')
            ->assertJsonPath('data.transfer_amount', '0.00')
            ->assertJsonPath('data.cash_commission_percentage', '0.00')
            ->assertJsonPath('data.cash_commission', '0.00')
            ->assertJsonPath('data.cash_amount', '9800.00')
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
        $this->assertDatabaseCount('histories', 10);

        foreach ([
            ['field_name' => 'delivery_date', 'previous_value' => null, 'new_value' => '2026-09-18'],
            ['field_name' => 'delivered_by_name', 'previous_value' => null, 'new_value' => 'Maria Lopez'],
            ['field_name' => 'received_by_name', 'previous_value' => null, 'new_value' => 'Juan Perez'],
            ['field_name' => 'prepared_return_amount', 'previous_value' => null, 'new_value' => '9800.00'],
            ['field_name' => 'requested_cash_amount', 'previous_value' => null, 'new_value' => '9800.00'],
            ['field_name' => 'cash_commission_percentage', 'previous_value' => null, 'new_value' => '0.00'],
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

    public function test_preparation_accepts_a_cash_shortfall_of_exactly_one_peso(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('120.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '120.00',
                'denominations' => [
                    ['value' => '100.00', 'quantity' => 1],
                    ['value' => '10.00', 'quantity' => 1],
                    ['value' => '5.00', 'quantity' => 1],
                    ['value' => '2.00', 'quantity' => 2],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.prepared_return_amount', '120.00')
            ->assertJsonPath('data.current_return_amount', '120.00')
            ->assertJsonPath('data.denominations', [
                ['value' => '100.00', 'quantity' => 1],
                ['value' => '10.00', 'quantity' => 1],
                ['value' => '5.00', 'quantity' => 1],
                ['value' => '2.00', 'quantity' => 2],
            ]);

        $this->assertDatabaseHas('deliveries', [
            'record_id' => $record->id,
            'prepared_return_amount' => '120.00',
        ]);
        $this->assertDatabaseHas('delivery_cash_denominations', [
            'value' => '100.00',
            'quantity' => 1,
        ]);
    }

    public function test_preparation_rejects_a_cash_shortfall_greater_than_one_peso_without_writes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('120.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '120.00',
                'denominations' => [
                    ['value' => '100.00', 'quantity' => 1],
                    ['value' => '10.00', 'quantity' => 1],
                    ['value' => '5.00', 'quantity' => 1],
                    ['value' => '2.00', 'quantity' => 1],
                    ['value' => '1.00', 'quantity' => 1],
                    ['value' => '0.50', 'quantity' => 1],
                ],
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'DELIVERY_PREPARATION_BLOCKED')
            ->assertJsonPath('details.reason', 'CASH_MISMATCH')
            ->assertJsonPath('details.expected_amount', '120.00')
            ->assertJsonPath('details.cash_amount', '118.50')
            ->assertJsonPath('details.difference', '-1.50');

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_preparation_accepts_any_excess_and_keeps_official_return_persisted(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('120.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '120.00',
                'denominations' => [
                    ['value' => '100.00', 'quantity' => 1],
                    ['value' => '50.00', 'quantity' => 1],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.prepared_return_amount', '120.00')
            ->assertJsonPath('data.current_return_amount', '120.00')
            ->assertJsonPath('data.is_stale', false)
            ->assertJsonPath('data.denominations.0.value', '100.00')
            ->assertJsonPath('data.denominations.1.value', '50.00');

        $this->assertDatabaseHas('deliveries', [
            'record_id' => $record->id,
            'prepared_return_amount' => '120.00',
        ]);
        $this->assertDatabaseCount('delivery_cash_denominations', 2);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'field_name' => 'prepared_return_amount',
            'new_value' => '120.00',
        ]);
    }

    public function test_zero_quantities_are_ignored_and_empty_breakdown_requires_zero_cash(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $zeroRecord = $this->createRecordWithReturn('0.00');
        $zeroDenominations = array_map(
            static fn (string $value): array => ['value' => $value, 'quantity' => 0],
            ['1000.00', '500.00', '200.00', '100.00', '50.00', '20.00', '10.00', '5.00', '2.00', '1.00', '0.50'],
        );

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$zeroRecord->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '0.00',
                'denominations' => $zeroDenominations,
            ]))
            ->assertOk()
            ->assertJsonPath('data.prepared_return_amount', '0.00')
            ->assertJsonPath('data.current_return_amount', '0.00')
            ->assertJsonPath('data.denominations', []);

        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 6);

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
                'requested_cash_amount' => '1000.00',
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

        $invalidSplits = [
            ['requested_cash_amount', null],
            ['requested_cash_amount', '-1.00'],
            ['requested_cash_amount', '100.005'],
            ['cash_commission_percentage', null],
            ['cash_commission_percentage', '100.01'],
            ['cash_commission_percentage', '-1.00'],
            ['cash_commission_percentage', '6.505'],
        ];

        foreach ($invalidSplits as [$field, $value]) {
            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([$field => $value]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $payloadWithoutSplit = $this->validPayload();
        unset($payloadWithoutSplit['requested_cash_amount'], $payloadWithoutSplit['cash_commission_percentage']);

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $payloadWithoutSplit)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['requested_cash_amount', 'cash_commission_percentage']);

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_client_cannot_send_derived_or_controlled_fields(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('9800.00');

        $fields = [
            'prepared_return_amount', 'transfer_amount', 'cash_commission', 'cash_amount',
            'record_id', 'status', 'source', 'reason', 'total', 'cash_total',
        ];

        foreach ($fields as $field) {
            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                    $field => 'anything',
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_mixed_split_persists_the_extra_commission_and_cash_to_deliver(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('200.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '100.00',
                'cash_commission_percentage' => '10.00',
                'denominations' => [
                    ['value' => '50.00', 'quantity' => 1],
                    ['value' => '20.00', 'quantity' => 2],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.prepared_return_amount', '200.00');

        $this->assertDatabaseHas('deliveries', [
            'record_id' => $record->id,
            'prepared_return_amount' => '200.00',
            'requested_cash_amount' => '100.00',
            'cash_commission_percentage' => '10.00',
            'cash_commission' => '10.00',
            'cash_amount' => '90.00',
        ]);
    }

    public function test_all_cash_split_delivers_the_return_minus_the_extra_commission(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('200.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '200.00',
                'cash_commission_percentage' => '10.00',
                'denominations' => [
                    ['value' => '100.00', 'quantity' => 1],
                    ['value' => '50.00', 'quantity' => 1],
                    ['value' => '20.00', 'quantity' => 1],
                    ['value' => '10.00', 'quantity' => 1],
                ],
            ]))
            ->assertOk();

        $this->assertDatabaseHas('deliveries', [
            'record_id' => $record->id,
            'requested_cash_amount' => '200.00',
            'cash_commission' => '20.00',
            'cash_amount' => '180.00',
        ]);
    }

    public function test_all_transfer_split_saves_an_empty_breakdown(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('200.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '0.00',
                'cash_commission_percentage' => '10.00',
                'denominations' => [],
            ]))
            ->assertOk()
            ->assertJsonPath('data.prepared_return_amount', '200.00')
            ->assertJsonPath('data.denominations', []);

        $this->assertDatabaseHas('deliveries', [
            'record_id' => $record->id,
            'requested_cash_amount' => '0.00',
            'cash_commission' => '0.00',
            'cash_amount' => '0.00',
        ]);
        $this->assertDatabaseCount('delivery_cash_denominations', 0);
    }

    public function test_requested_cash_above_the_current_return_is_blocked_without_writes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('200.00');

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                'requested_cash_amount' => '200.01',
                'cash_commission_percentage' => '10.00',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'DELIVERY_PREPARATION_BLOCKED')
            ->assertJsonPath('details.reason', 'CASH_REQUEST_EXCEEDS_RETURN')
            ->assertJsonPath('details.requested_cash_amount', '200.01')
            ->assertJsonPath('details.current_return_amount', '200.00');

        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_cash_denominations', 0);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_the_breakdown_reconciles_against_the_cash_to_deliver(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithReturn('200.00');
        $split = ['requested_cash_amount' => '100.00', 'cash_commission_percentage' => '10.00'];

        // 88.99 is 1.01 below the 90.00 to deliver (the official return is 200.00).
        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                ...$split,
                'denominations' => [
                    ['value' => '50.00', 'quantity' => 1],
                    ['value' => '20.00', 'quantity' => 1],
                    ['value' => '10.00', 'quantity' => 1],
                    ['value' => '5.00', 'quantity' => 1],
                    ['value' => '2.00', 'quantity' => 1],
                    ['value' => '1.00', 'quantity' => 1],
                    ['value' => '0.50', 'quantity' => 1],
                ],
            ]))
            ->assertStatus(409)
            ->assertJsonPath('details.reason', 'CASH_MISMATCH')
            ->assertJsonPath('details.expected_amount', '90.00')
            ->assertJsonPath('details.cash_amount', '88.50')
            ->assertJsonPath('details.difference', '-1.50');

        $this->assertDatabaseCount('deliveries', 0);

        // 89.00 is exactly one peso below: accepted.
        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id.'/delivery', $this->validPayload([
                ...$split,
                'denominations' => [
                    ['value' => '50.00', 'quantity' => 1],
                    ['value' => '20.00', 'quantity' => 1],
                    ['value' => '10.00', 'quantity' => 1],
                    ['value' => '5.00', 'quantity' => 1],
                    ['value' => '2.00', 'quantity' => 2],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.prepared_return_amount', '200.00');

        $this->assertDatabaseHas('deliveries', ['record_id' => $record->id, 'cash_amount' => '90.00']);
    }
}
