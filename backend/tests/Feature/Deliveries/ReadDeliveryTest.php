<?php

namespace Tests\Feature\Deliveries;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\BuildsDeliveryPayloads;
use Tests\TestCase;

class ReadDeliveryTest extends TestCase
{
    use BuildsDeliveryPayloads, RefreshDatabase;

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

    public function test_editors_and_viewers_read_the_transfer_and_cash_split(): void
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

        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')
                ->getJson('/api/records/'.$record->id.'/delivery')
                ->assertOk()
                ->assertJsonPath('data.prepared_return_amount', '200.00')
                ->assertJsonPath('data.current_return_amount', '200.00')
                ->assertJsonPath('data.is_stale', false)
                ->assertJsonPath('data.requested_cash_amount', '100.00')
                ->assertJsonPath('data.transfer_amount', '100.00')
                ->assertJsonPath('data.cash_commission_percentage', '10.00')
                ->assertJsonPath('data.cash_commission', '10.00')
                ->assertJsonPath('data.cash_amount', '90.00');
        }
    }
}
