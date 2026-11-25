<?php

namespace Tests\Feature\Records;

use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\BuildsManualRecords;
use Tests\TestCase;

class ShowRecordTest extends TestCase
{
    use BuildsManualRecords, RefreshDatabase;

    public function test_record_reads_require_authentication(): void
    {
        $record = Record::factory()->create();

        $this->getJson('/api/records')->assertUnauthorized();
        $this->getJson('/api/records/'.$record->id)->assertUnauthorized();
    }

    public function test_editors_and_viewers_can_list_and_view_records(): void
    {
        $record = $this->createRecordWithDetails();

        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'web')
                ->getJson('/api/records')
                ->assertOk()
                ->assertJsonPath('data.0.id', $record->id)
                ->assertJsonPath('data.0.company.id', $record->company_id)
                ->assertJsonPath('data.0.source', RecordSource::MANUAL->value)
                ->assertJsonPath('data.0.status', RecordStatus::NEEDS_REVIEW->value)
                ->assertJsonPath('data.0.details.0.affiliation', 'AFF-12345')
                ->assertJsonPath('data.0.details.0.bank', 'LUUM')
                ->assertJsonMissingPath('data.0.details.0.branch');

            $this->actingAs($user, 'web')
                ->getJson('/api/records/'.$record->id)
                ->assertOk()
                ->assertJsonPath('data.id', $record->id)
                ->assertJsonPath('data.commission_percentage', '5.00')
                ->assertJsonPath('data.details.0.affiliation', 'AFF-12345')
                ->assertJsonPath('data.details.0.bank', 'LUUM')
                ->assertJsonPath('data.details.0.income', '100.00')
                ->assertJsonMissingPath('data.details.0.commission_percentage')
                ->assertJsonMissingPath('data.details.0.commission')
                ->assertJsonMissingPath('data.details.0.return_amount')
                ->assertJsonPath('data.income_total', '100.00')
                ->assertJsonPath('data.commission_total', '5.00')
                ->assertJsonPath('data.return_total', '95.00');
        }
    }

    public function test_totals_come_from_the_record_and_are_not_multiplied_by_its_movements(): void
    {
        // 3 × 100.10 = 300.30; 300.30 × 6.5% = 19.52 once over the total.
        $record = $this->createRecordWithDetails(null, [
            'commission_percentage' => '6.50',
            'commission' => '19.52',
            'return_amount' => '280.78',
        ], ['income' => '100.10']);
        foreach ([2, 3] as $order) {
            $record->details()->create(['affiliation' => "AFF-{$order}", 'bank' => 'LUUM', 'income' => '100.10', 'order' => $order]);
        }

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records/'.$record->id)
            ->assertOk()
            ->assertJsonCount(3, 'data.details')
            ->assertJsonPath('data.commission_percentage', '6.50')
            ->assertJsonPath('data.income_total', '300.30')
            ->assertJsonPath('data.commission_total', '19.52')
            ->assertJsonPath('data.return_total', '280.78');
    }

    public function test_a_provisional_record_without_percentage_omits_the_derived_totals(): void
    {
        $record = $this->createRecordWithDetails(null, [
            'source' => RecordSource::IMAGE,
            'commission_percentage' => null,
            'commission' => null,
            'return_amount' => null,
        ]);

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records/'.$record->id)
            ->assertOk()
            ->assertJsonPath('data.commission_percentage', null)
            ->assertJsonPath('data.income_total', '100.00')
            ->assertJsonMissingPath('data.commission_total')
            ->assertJsonMissingPath('data.return_total');
    }

    public function test_show_returns_not_found_for_unknown_record(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records/999999')
            ->assertNotFound();
    }
}
