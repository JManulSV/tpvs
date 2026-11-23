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

    public function test_editor_can_create_a_manual_record_and_backend_calculates_money(): void
    {
        $company = Company::factory()->create();
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);

        $response = $this->actingAs($editor, 'web')
            ->postJson('/api/records', $this->validManualRecordPayload($company->id, [
                [
                    'affiliation' => 'AFF-FIRST',
                    'branch' => 'Guadalajara',
                    'income' => '0.10',
                    'commission_percentage' => '5.00',
                ],
                [
                    'affiliation' => 'AFF-SECOND',
                    'branch' => 'Zapopan',
                    'income' => '100.00',
                    'commission' => '7.25',
                ],
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.source', RecordSource::MANUAL->value)
            ->assertJsonPath('data.status', RecordStatus::CONFIRMED->value)
            ->assertJsonMissingPath('data.affiliation')
            ->assertJsonMissingPath('data.branch')
            ->assertJsonPath('data.details.0.order', 1)
            ->assertJsonPath('data.details.0.affiliation', 'AFF-FIRST')
            ->assertJsonPath('data.details.0.branch', 'Guadalajara')
            ->assertJsonPath('data.details.0.commission_percentage', '5.00')
            ->assertJsonPath('data.details.0.commission', '0.01')
            ->assertJsonPath('data.details.0.return_amount', '0.09')
            ->assertJsonPath('data.details.1.commission_percentage', null)
            ->assertJsonPath('data.details.1.affiliation', 'AFF-SECOND')
            ->assertJsonPath('data.details.1.branch', 'Zapopan')
            ->assertJsonPath('data.details.1.commission', '7.25')
            ->assertJsonPath('data.details.1.return_amount', '92.75')
            ->assertJsonPath('data.income_total', '100.10')
            ->assertJsonPath('data.commission_total', '7.26')
            ->assertJsonPath('data.return_total', '92.84');

        $recordId = $response->json('data.id');
        $this->assertDatabaseHas('records', [
            'id' => $recordId,
            'company_id' => $company->id,
            'source' => RecordSource::MANUAL->value,
            'status' => RecordStatus::CONFIRMED->value,
        ]);
        $this->assertDatabaseCount('record_details', 2);
        $this->assertDatabaseHas('record_details', [
            'record_id' => $recordId,
            'order' => 1,
            'affiliation' => 'AFF-FIRST',
            'branch' => 'Guadalajara',
        ]);
        $this->assertDatabaseHas('record_details', [
            'record_id' => $recordId,
            'order' => 2,
            'affiliation' => 'AFF-SECOND',
            'branch' => 'Zapopan',
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
                'details',
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

    public function test_manual_payload_requires_affiliation_and_branch_on_each_detail_and_rejects_root_fields(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $payload = $this->validManualRecordPayload(Company::factory()->create()->id);

        $missingMovementFields = $payload;
        unset($missingMovementFields['details'][0]['affiliation'], $missingMovementFields['details'][0]['branch']);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', $missingMovementFields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details.0.affiliation', 'details.0.branch']);

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

    public function test_manual_creation_rejects_invalid_commission_inputs_without_partial_writes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $companyId = Company::factory()->create()->id;
        $payload = $this->validManualRecordPayload($companyId);

        foreach ([
            ['income' => '10.00'],
            ['income' => '10.00', 'commission' => '1.00', 'commission_percentage' => '5.00'],
            ['income' => '10.00', 'commission' => '10.01'],
            ['income' => '10.00', 'commission_percentage' => '100.01'],
            ['income' => '10.001', 'commission' => '1.00'],
            ['income' => '-1.00', 'commission' => '0.00'],
        ] as $invalidDetail) {
            $invalidPayload = $payload;
            $invalidPayload['details'] = [array_merge([
                'affiliation' => 'AFF-INVALID',
                'branch' => 'Guadalajara',
            ], $invalidDetail)];

            $this->actingAs($editor, 'web')
                ->postJson('/api/records', $invalidPayload)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('record_details', 0);
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
