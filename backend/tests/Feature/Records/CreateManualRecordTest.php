<?php

namespace Tests\Feature\Records;

use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\History;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Concerns\BuildsManualRecords;
use Tests\TestCase;

class CreateManualRecordTest extends TestCase
{
    use BuildsManualRecords, RefreshDatabase;

    public function test_editor_can_create_a_manual_record_and_backend_calculates_the_global_commission(): void
    {
        $company = Company::factory()->create();
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);

        // test-images/nuevo-formato.png: 236741.30 × 6.5% = 15388.1845 → 15388.18
        $response = $this->actingAs($editor, 'web')
            ->postJson('/api/records', $this->validManualRecordPayload($company->id, [
                ['affiliation' => '8889067', 'bank' => 'LUUM', 'income' => '236741.30'],
            ], '6.50'));

        $response->assertCreated()
            ->assertJsonPath('data.source', RecordSource::MANUAL->value)
            ->assertJsonPath('data.status', RecordStatus::CONFIRMED->value)
            ->assertJsonMissingPath('data.affiliation')
            ->assertJsonMissingPath('data.branch')
            ->assertJsonPath('data.commission_percentage', '6.50')
            ->assertJsonPath('data.details.0.affiliation', '8889067')
            ->assertJsonPath('data.details.0.bank', 'LUUM')
            ->assertJsonPath('data.details.0.income', '236741.30')
            ->assertJsonPath('data.income_total', '236741.30')
            ->assertJsonPath('data.commission_total', '15388.18')
            ->assertJsonPath('data.return_total', '221353.12');

        $recordId = $response->json('data.id');
        $this->assertDatabaseHas('records', [
            'id' => $recordId,
            'company_id' => $company->id,
            'source' => RecordSource::MANUAL->value,
            'status' => RecordStatus::CONFIRMED->value,
            'commission_percentage' => '6.50',
            'commission' => '15388.18',
            'return_amount' => '221353.12',
        ]);
        $this->assertDatabaseCount('record_details', 1);
        $this->assertDatabaseHas('record_details', [
            'record_id' => $recordId,
            'order' => 1,
            'affiliation' => '8889067',
            'bank' => 'LUUM',
            'income' => '236741.30',
        ]);
        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'record_id' => $recordId,
            'user_id' => $editor->id,
            'field_name' => 'record_created',
            'previous_value' => null,
            'new_value' => RecordStatus::CONFIRMED->value,
            'reason' => 'Registro creado manualmente',
        ]);
        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('ai_processings', 0);
    }

    public function test_the_commission_is_calculated_once_over_the_sum_of_incomes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);

        // Per movement: 100.10 × 6.5% = 6.5065 → 6.51, twice = 13.02.
        // Over the total: 200.20 × 6.5% = 13.013 → 13.01.
        $response = $this->actingAs($editor, 'web')
            ->postJson('/api/records', $this->validManualRecordPayload(Company::factory()->create()->id, [
                ['affiliation' => 'AFF-FIRST', 'bank' => 'LUUM', 'income' => '100.10'],
                ['affiliation' => 'AFF-SECOND', 'bank' => 'BBVA', 'income' => '100.10'],
            ], '6.50'));

        $response->assertCreated()
            ->assertJsonPath('data.income_total', '200.20')
            ->assertJsonPath('data.commission_total', '13.01')
            ->assertJsonPath('data.return_total', '187.19');
        $recordId = $response->json('data.id');
        $this->assertDatabaseHas('records', [
            'id' => $recordId,
            'commission' => '13.01',
            'return_amount' => '187.19',
        ]);
        $this->assertDatabaseHas('record_details', ['record_id' => $recordId, 'order' => 1, 'bank' => 'LUUM']);
        $this->assertDatabaseHas('record_details', ['record_id' => $recordId, 'order' => 2, 'bank' => 'BBVA']);
    }

    public function test_record_creation_requires_authentication_and_editor_role(): void
    {
        $payload = $this->validManualRecordPayload(Company::factory()->create()->id);

        $this->postJson('/api/records', $payload)->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => UserRole::VIEWER]), 'web')
            ->postJson('/api/records', $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('records', 0);
    }

    public function test_manual_creation_validates_required_fields_details_and_company(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $validPayload = $this->validManualRecordPayload(Company::factory()->create()->id);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company_id', 'year', 'week', 'week_start_date', 'week_end_date',
                'commission_percentage', 'details',
            ]);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', array_merge($validPayload, [
                'company_id' => 999999,
                'details' => [],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_id', 'details']);

        $this->assertDatabaseCount('records', 0);
    }

    public function test_manual_payload_requires_affiliation_bank_and_income_on_each_detail_and_rejects_root_fields(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $payload = $this->validManualRecordPayload(Company::factory()->create()->id);

        $missingMovementFields = $payload;
        unset(
            $missingMovementFields['details'][0]['affiliation'],
            $missingMovementFields['details'][0]['bank'],
            $missingMovementFields['details'][0]['income'],
        );

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', $missingMovementFields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details.0.affiliation', 'details.0.bank', 'details.0.income']);

        $legacyRootFields = $payload;
        $legacyRootFields['affiliation'] = 'AFF-ROOT';
        $legacyRootFields['branch'] = 'Root branch';

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', $legacyRootFields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['affiliation', 'branch']);

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('record_details', 0);
    }

    public function test_manual_creation_requires_the_global_commission_percentage(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $payload = $this->validManualRecordPayload(Company::factory()->create()->id);
        unset($payload['commission_percentage']);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commission_percentage']);

        $this->assertDatabaseCount('records', 0);
    }

    public function test_manual_creation_rejects_legacy_and_derived_commission_fields(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $payload = $this->validManualRecordPayload(Company::factory()->create()->id);

        foreach ([
            ['branch' => 'Guadalajara'],
            ['commission' => '5.00'],
            ['return_amount' => '95.00'],
        ] as $rootField) {
            $this->actingAs($editor, 'web')
                ->postJson('/api/records', array_merge($payload, $rootField))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(array_keys($rootField));
        }

        foreach ([
            'branch' => 'Guadalajara',
            'commission_percentage' => '5.00',
            'commission' => '5.00',
            'return_amount' => '95.00',
        ] as $field => $value) {
            $invalidPayload = $payload;
            $invalidPayload['details'][0][$field] = $value;

            $this->actingAs($editor, 'web')
                ->postJson('/api/records', $invalidPayload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(["details.0.{$field}"]);
        }

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('record_details', 0);
    }

    public function test_manual_creation_accepts_business_week_numbers_and_non_monday_date_ranges(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $companyId = Company::factory()->create()->id;

        $businessPeriod = $this->validManualRecordPayload($companyId);
        $businessPeriod['year'] = 2025;
        $businessPeriod['week'] = 53;
        $businessPeriod['week_start_date'] = '2025-09-17';
        $businessPeriod['week_end_date'] = '2025-09-25';

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', $businessPeriod)
            ->assertCreated()
            ->assertJsonPath('data.year', 2025)
            ->assertJsonPath('data.week', 53)
            ->assertJsonPath('data.week_start_date', '2025-09-17')
            ->assertJsonPath('data.week_end_date', '2025-09-25');

        $invalidRange = $this->validManualRecordPayload($companyId);
        $invalidRange['week_start_date'] = '2026-09-21';
        $invalidRange['week_end_date'] = '2026-09-20';

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', $invalidRange)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['week_end_date']);

        $this->assertDatabaseCount('records', 1);
    }

    public function test_manual_creation_rejects_invalid_percentages_and_incomes_without_partial_writes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $companyId = Company::factory()->create()->id;

        foreach ([
            [['income' => '10.00'], '100.01', 'commission_percentage'],
            [['income' => '10.00'], '-1.00', 'commission_percentage'],
            [['income' => '10.00'], '6.505', 'commission_percentage'],
            [['income' => '10.00'], 'abc', 'commission_percentage'],
            [['income' => '10.001'], '6.50', 'details.0.income'],
            [['income' => '-1.00'], '6.50', 'details.0.income'],
        ] as [$detail, $percentage, $errorKey]) {
            $this->actingAs($editor, 'web')
                ->postJson('/api/records', $this->validManualRecordPayload($companyId, [$detail], $percentage))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$errorKey]);
        }

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('record_details', 0);
    }

    public function test_zero_and_full_percentages_are_accepted(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $companyId = Company::factory()->create()->id;

        foreach ([['0', '0.00', '100.00'], ['100', '100.00', '0.00']] as [$percentage, $commission, $return]) {
            $recordId = $this->actingAs($editor, 'web')
                ->postJson('/api/records', $this->validManualRecordPayload($companyId, null, $percentage))
                ->assertCreated()
                ->json('data.id');

            $this->assertDatabaseHas('records', [
                'id' => $recordId,
                'commission' => $commission,
                'return_amount' => $return,
            ]);
        }
    }

    public function test_failed_creation_history_write_rolls_back_manual_record_and_details(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $payload = $this->validManualRecordPayload(Company::factory()->create()->id);

        Event::listen('eloquent.creating: '.History::class, static function (): void {
            throw new \RuntimeException('Simulated history persistence failure.');
        });

        try {
            $this->actingAs($editor, 'web')
                ->postJson('/api/records', $payload)
                ->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.History::class);
        }

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('record_details', 0);
        $this->assertDatabaseCount('histories', 0);
        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('ai_processings', 0);
    }
}
