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
                ->assertJsonPath('data.0.details.0.branch', 'Guadalajara');

            $this->actingAs($user, 'web')
                ->getJson('/api/records/'.$record->id)
                ->assertOk()
                ->assertJsonPath('data.id', $record->id)
                ->assertJsonPath('data.details.0.income', '100.00')
                ->assertJsonPath('data.details.0.commission', '5.00')
                ->assertJsonPath('data.details.0.return_amount', '95.00')
                ->assertJsonPath('data.income_total', '100.00')
                ->assertJsonPath('data.commission_total', '5.00')
                ->assertJsonPath('data.return_total', '95.00');
        }
    }

    public function test_show_returns_not_found_for_unknown_record(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records/999999')
            ->assertNotFound();
    }
}
