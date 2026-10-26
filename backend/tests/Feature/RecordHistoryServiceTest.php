<?php

namespace Tests\Feature;

use App\Domain\Records\RecordHistoryService;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_canonicalizes_record_and_detail_values(): void
    {
        $company = Company::factory()->create(['name' => '  Acme  ']);
        $record = Record::factory()->for($company)->create([
            'source' => RecordSource::MANUAL,
            'status' => RecordStatus::NEEDS_REVIEW,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ]);
        $record->details()->create([
            'affiliation' => ' AFF-123 ',
            'branch' => ' Guadalajara ',
            'income' => '100',
            'commission_percentage' => '5',
            'commission' => '5.00',
            'return_amount' => '95.00',
            'order' => 1,
        ]);

        $snapshot = $this->service()->snapshot($record);

        $this->assertSame('Acme', $snapshot['company']);
        $this->assertSame('2026', $snapshot['year']);
        $this->assertSame('38', $snapshot['week']);
        $this->assertSame('2026-09-14', $snapshot['week_start_date']);
        $this->assertSame('2026-09-20', $snapshot['week_end_date']);
        $this->assertSame('1', $snapshot['details[1].order']);
        $this->assertSame('AFF-123', $snapshot['details[1].affiliation']);
        $this->assertSame('Guadalajara', $snapshot['details[1].branch']);
        $this->assertSame('100.00', $snapshot['details[1].income']);
        $this->assertSame('5.00', $snapshot['details[1].commission_percentage']);
        $this->assertSame('5.00', $snapshot['details[1].commission']);
        $this->assertSame('95.00', $snapshot['details[1].return_amount']);
    }

    public function test_diff_compares_record_and_details_by_order(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        $otherCompany = Company::factory()->create(['name' => 'Contoso']);
        $record = Record::factory()->for($company)->create([
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ]);
        $record->details()->create([
            'affiliation' => 'AFF-1',
            'branch' => 'Guadalajara',
            'income' => '100.00',
            'commission' => '5.00',
            'return_amount' => '95.00',
            'order' => 1,
        ]);

        $before = $this->service()->snapshot($record);

        $record->update([
            'company_id' => $otherCompany->id,
            'year' => 2027,
            'week' => 39,
            'week_start_date' => '2027-09-20',
            'week_end_date' => '2027-09-26',
        ]);
        $record->details()->delete();
        $record->details()->createMany([
            [
                'affiliation' => 'AFF-1',
                'branch' => 'Guadalajara',
                'income' => '120.00',
                'commission' => '6.00',
                'return_amount' => '114.00',
                'order' => 1,
            ],
            [
                'affiliation' => 'AFF-2',
                'branch' => 'Zapopan',
                'income' => '50.00',
                'commission' => '2.50',
                'return_amount' => '47.50',
                'order' => 2,
            ],
        ]);

        $after = $this->service()->snapshot($record);
        $changes = collect($this->service()->diff($before, $after))->keyBy('field_name');

        $this->assertSame('Acme', $changes['company']['previous_value']);
        $this->assertSame('Contoso', $changes['company']['new_value']);
        $this->assertSame('2026', $changes['year']['previous_value']);
        $this->assertSame('2027', $changes['year']['new_value']);
        $this->assertSame('2026-09-20', $changes['week_end_date']['previous_value']);
        $this->assertSame('2027-09-26', $changes['week_end_date']['new_value']);
        $this->assertSame('100.00', $changes['details[1].income']['previous_value']);
        $this->assertSame('120.00', $changes['details[1].income']['new_value']);
        $this->assertSame('95.00', $changes['details[1].return_amount']['previous_value']);
        $this->assertSame('114.00', $changes['details[1].return_amount']['new_value']);
        $this->assertNull($changes['details[2].order']['previous_value']);
        $this->assertSame('2', $changes['details[2].order']['new_value']);
        $this->assertNull($changes['details[2].income']['previous_value']);
        $this->assertSame('50.00', $changes['details[2].income']['new_value']);
    }

    public function test_diff_marks_removed_details_with_null_new_values(): void
    {
        $record = Record::factory()->create();
        $record->details()->createMany([
            [
                'affiliation' => 'AFF-1',
                'branch' => 'Guadalajara',
                'income' => '100.00',
                'commission' => '5.00',
                'return_amount' => '95.00',
                'order' => 1,
            ],
            [
                'affiliation' => 'AFF-2',
                'branch' => 'Zapopan',
                'income' => '50.00',
                'commission' => '2.50',
                'return_amount' => '47.50',
                'order' => 2,
            ],
        ]);

        $before = $this->service()->snapshot($record);
        $record->details()->where('order', 2)->delete();
        $after = $this->service()->snapshot($record);

        $changes = collect($this->service()->diff($before, $after))->keyBy('field_name');

        $this->assertSame('50.00', $changes['details[2].income']['previous_value']);
        $this->assertNull($changes['details[2].income']['new_value']);
        $this->assertSame('2', $changes['details[2].order']['previous_value']);
        $this->assertNull($changes['details[2].order']['new_value']);
        $this->assertArrayNotHasKey('details[1].income', $changes->all());
    }

    public function test_write_field_changes_creates_one_history_row_per_changed_field(): void
    {
        $record = Record::factory()->create();
        $user = User::factory()->create();

        $changes = $this->service()->writeFieldChanges(
            $record,
            [
                'year' => '2026',
                'week_end_date' => '2026-09-20',
                'details[1].income' => '100.00',
            ],
            [
                'year' => '2027',
                'week_end_date' => '2026-09-20',
                'details[1].income' => '120.00',
            ],
            $user->id,
            'Se corrigió la captura',
        );

        $this->assertCount(2, $changes);
        $this->assertDatabaseCount('histories', 2);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $user->id,
            'field_name' => 'year',
            'previous_value' => '2026',
            'new_value' => '2027',
            'reason' => 'Se corrigió la captura',
            'record_detail_id' => null,
            'delivery_id' => null,
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $user->id,
            'field_name' => 'details[1].income',
            'previous_value' => '100.00',
            'new_value' => '120.00',
            'reason' => 'Se corrigió la captura',
            'record_detail_id' => null,
            'delivery_id' => null,
        ]);
    }

    public function test_write_field_changes_does_not_create_rows_without_differences(): void
    {
        $record = Record::factory()->create();
        $user = User::factory()->create();
        $snapshot = ['year' => '2026', 'details[1].income' => '100.00'];

        $changes = $this->service()->writeFieldChanges(
            $record,
            $snapshot,
            $snapshot,
            $user->id,
            'Sin cambios',
        );

        $this->assertSame([], $changes);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_creation_update_and_status_helpers_write_canonical_events(): void
    {
        $record = Record::factory()->create();
        $user = User::factory()->create();

        $this->service()->writeRecordCreated(
            $record,
            $user->id,
            RecordStatus::CONFIRMED,
            'Registro creado manualmente',
        );
        $this->service()->writeRecordUpdated($record, $user->id, 'Sin diferencias');
        $this->service()->writeStatusChange(
            $record,
            RecordStatus::CONFIRMED,
            RecordStatus::NEEDS_REVIEW,
            $user->id,
            'Se reabre para corrección',
        );

        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $user->id,
            'field_name' => 'record_created',
            'previous_value' => null,
            'new_value' => RecordStatus::CONFIRMED->value,
            'reason' => 'Registro creado manualmente',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $user->id,
            'field_name' => 'record_updated',
            'previous_value' => null,
            'new_value' => null,
            'reason' => 'Sin diferencias',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $user->id,
            'field_name' => 'status',
            'previous_value' => RecordStatus::CONFIRMED->value,
            'new_value' => RecordStatus::NEEDS_REVIEW->value,
            'reason' => 'Se reabre para corrección',
        ]);
        $this->assertDatabaseCount('histories', 3);
    }

    private function service(): RecordHistoryService
    {
        return app(RecordHistoryService::class);
    }
}
