<?php

namespace Tests\Feature;

use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\History;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RecordsApiTest extends TestCase
{
    use RefreshDatabase;

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
                ->assertJsonPath('data.0.details.0.operation_number', 'OP-12345');

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

    public function test_list_supports_case_insensitive_search_across_record_fields(): void
    {
        $company = Company::factory()->create(['name' => 'Northwind Supply']);
        $record = $this->createRecordWithDetails($company, [
            'affiliation' => 'AFF-928',
            'branch' => 'Guadalajara Centro',
        ], ['operation_number' => 'OP-74531']);
        $other = $this->createRecordWithDetails(Company::factory()->create(['name' => 'Contoso']));

        foreach ([' NORTHWIND ', 'op-745', 'aff-928', 'GUADALAJARA'] as $search) {
            $this->actingAs(User::factory()->create(), 'web')
                ->getJson('/api/records?search='.urlencode($search))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $record->id)
                ->assertJsonPath('meta.total', 1);
        }

        $this->assertNotSame($record->id, $other->id);
    }

    public function test_list_filters_records_and_month_uses_week_end_date(): void
    {
        $company = Company::factory()->create();
        $matching = $this->createRecordWithDetails($company, [
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
            'source' => RecordSource::MANUAL,
            'status' => RecordStatus::CONFIRMED,
        ]);
        $this->createRecordWithDetails($company, [
            'year' => 2026,
            'week' => 39,
            'week_start_date' => '2026-09-21',
            'week_end_date' => '2026-09-27',
            'source' => RecordSource::IMAGE,
            'status' => RecordStatus::NEEDS_REVIEW,
        ]);
        $this->createRecordWithDetails(Company::factory()->create(), [
            'year' => 2025,
            'week' => 38,
            'week_start_date' => '2025-09-15',
            'week_end_date' => '2025-09-21',
        ]);

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records?company_id='.$company->id.'&year=2026&month=9&week=38&status=CONFIRMED&source=MANUAL')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_list_is_paginated_and_ordered_by_most_recent_update(): void
    {
        $older = $this->createRecordWithDetails();
        $newer = $this->createRecordWithDetails();
        $oldTime = now()->subMinute();
        $newTime = now();
        $older->forceFill(['updated_at' => $oldTime])->save();
        $newer->forceFill(['updated_at' => $newTime])->save();

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('links.next', fn ($value) => is_string($value));
    }

    public function test_invalid_filters_and_pagination_return_validation_errors(): void
    {
        $user = User::factory()->create();

        foreach ([
            '/api/records?page=0',
            '/api/records?per_page=101',
            '/api/records?per_page=not-a-number',
            '/api/records?search='.str_repeat('a', 256),
            '/api/records?company_id=999999',
            '/api/records?month=13',
            '/api/records?status=UNKNOWN',
            '/api/records?source=UNKNOWN',
            '/api/records?week=1',
            '/api/records?year=2025&week=53',
        ] as $url) {
            $this->actingAs($user, 'web')
                ->getJson($url)
                ->assertUnprocessable("Expected validation error for {$url}");
        }
    }

    public function test_show_returns_not_found_for_unknown_record(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/records/999999')
            ->assertNotFound();
    }

    public function test_editor_can_create_a_manual_record_and_backend_calculates_money(): void
    {
        $company = Company::factory()->create();
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);

        $response = $this->actingAs($editor, 'web')
            ->postJson('/api/records', $this->validManualRecordPayload($company->id, [
                [
                    'operation_number' => 'OP-HALF-UP',
                    'income' => '0.10',
                    'commission_percentage' => '5.00',
                ],
                [
                    'operation_number' => 'OP-FIXED',
                    'income' => '100.00',
                    'commission' => '7.25',
                ],
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.source', RecordSource::MANUAL->value)
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.details.0.order', 1)
            ->assertJsonPath('data.details.0.commission_percentage', '5.00')
            ->assertJsonPath('data.details.0.commission', '0.01')
            ->assertJsonPath('data.details.0.return_amount', '0.09')
            ->assertJsonPath('data.details.1.commission_percentage', null)
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
            'status' => RecordStatus::NEEDS_REVIEW->value,
        ]);
        $this->assertDatabaseCount('record_details', 2);
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
                'affiliation', 'branch', 'details',
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

    public function test_manual_creation_rejects_invalid_iso_weeks_and_dates(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $companyId = Company::factory()->create()->id;

        $invalidWeek = $this->validManualRecordPayload($companyId);
        $invalidWeek['year'] = 2025;
        $invalidWeek['week'] = 53;

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', $invalidWeek)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['week']);

        $invalidDates = $this->validManualRecordPayload($companyId);
        $invalidDates['week_start_date'] = '2026-09-15';

        $this->actingAs($editor, 'web')
            ->postJson('/api/records', $invalidDates)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['week_start_date', 'week_end_date']);

        $this->assertDatabaseCount('records', 0);
    }

    public function test_manual_creation_accepts_an_iso_week_that_crosses_calendar_years(): void
    {
        $company = Company::factory()->create();
        $payload = $this->validManualRecordPayload($company->id);
        $payload['year'] = 2020;
        $payload['week'] = 53;
        $payload['week_start_date'] = '2020-12-28';
        $payload['week_end_date'] = '2021-01-03';

        $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->postJson('/api/records', $payload)
            ->assertCreated()
            ->assertJsonPath('data.year', 2020)
            ->assertJsonPath('data.week', 53)
            ->assertJsonPath('data.week_start_date', '2020-12-28')
            ->assertJsonPath('data.week_end_date', '2021-01-03');
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
                'operation_number' => 'OP-INVALID',
            ], $invalidDetail)];

            $this->actingAs($editor, 'web')
                ->postJson('/api/records', $invalidPayload)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('record_details', 0);
    }

    public function test_editor_can_replace_manual_needs_review_record_and_logs_generic_history(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();
        $newCompany = Company::factory()->create();
        $payload = $this->validManualRecordPayload($newCompany->id, [
            [
                'operation_number' => 'OP-NEW-2',
                'income' => '200.00',
                'commission_percentage' => '10.00',
            ],
            [
                'operation_number' => 'OP-NEW-1',
                'income' => '50.00',
                'commission' => '5.00',
            ],
        ]);
        $payload['branch'] = 'Nueva sucursal';
        $payload['reason'] = 'Se corrigieron importes y sucursal';

        $response = $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload);

        $response->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.company.id', $newCompany->id)
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.branch', 'Nueva sucursal')
            ->assertJsonCount(2, 'data.details')
            ->assertJsonPath('data.details.0.operation_number', 'OP-NEW-2')
            ->assertJsonPath('data.details.0.order', 1)
            ->assertJsonPath('data.details.0.commission', '20.00')
            ->assertJsonPath('data.details.1.operation_number', 'OP-NEW-1')
            ->assertJsonPath('data.details.1.order', 2)
            ->assertJsonPath('data.details.1.return_amount', '45.00');

        $this->assertDatabaseCount('record_details', 2);
        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'manual_record_updated',
            'previous_value' => null,
            'new_value' => null,
            'reason' => 'Se corrigieron importes y sucursal',
        ]);
    }

    public function test_record_replacement_requires_authentication_and_editor_role(): void
    {
        $record = $this->createRecordWithDetails();
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['reason'] = 'Correction';

        $this->putJson('/api/records/'.$record->id, $payload)->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => UserRole::VIEWER]), 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('histories', 0);
        $this->assertDatabaseCount('record_details', 1);
    }

    public function test_record_replacement_requires_full_valid_payload_and_reason(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company_id', 'year', 'week', 'week_start_date', 'week_end_date',
                'affiliation', 'branch', 'details', 'reason',
            ]);

        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['reason'] = '   ';
        $payload['details'][0]['income'] = '20.00';
        $payload['details'][0]['commission'] = '25.00';
        unset($payload['details'][0]['commission_percentage']);

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseCount('histories', 0);
        $this->assertDatabaseCount('record_details', 1);
        $this->assertDatabaseHas('record_details', [
            'record_id' => $record->id,
            'income' => '100.00',
        ]);
    }

    public function test_only_manual_records_needing_review_can_be_replaced(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $confirmed = $this->createRecordWithDetails(null, ['status' => RecordStatus::CONFIRMED]);
        $imageRecord = $this->createRecordWithDetails(null, ['source' => RecordSource::IMAGE]);
        $payload = $this->validManualRecordPayload($confirmed->company_id);
        $payload['reason'] = 'Attempted edit';

        foreach ([[$confirmed, 'INVALID_STATE'], [$imageRecord, 'INVALID_SOURCE']] as [$record, $reason]) {
            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id, $payload)
                ->assertStatus(409)
                ->assertJsonPath('code', 'RECORD_UPDATE_BLOCKED')
                ->assertJsonPath('details.reason', $reason);
        }

        $this->assertDatabaseCount('histories', 0);
        $this->assertDatabaseCount('record_details', 2);
    }

    public function test_failed_history_write_rolls_back_record_and_detail_replacement(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();
        $payload = $this->validManualRecordPayload($record->company_id, [[
            'operation_number' => 'OP-ROLLBACK',
            'income' => '500.00',
            'commission' => '50.00',
        ]]);
        $payload['branch'] = 'Should roll back';
        $payload['reason'] = 'Test rollback';

        Event::listen('eloquent.creating: '.History::class, static function (): void {
            throw new \RuntimeException('Simulated history persistence failure.');
        });

        try {
            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id, $payload)
                ->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.History::class);
        }

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'branch' => $record->branch,
        ]);
        $this->assertDatabaseCount('record_details', 1);
        $this->assertDatabaseHas('record_details', [
            'record_id' => $record->id,
            'operation_number' => 'OP-12345',
        ]);
        $this->assertDatabaseCount('histories', 0);
    }

    /**
     * @param  array<string, mixed>  $recordAttributes
     * @param  array<string, mixed>  $detailAttributes
     */
    private function createRecordWithDetails(
        ?Company $company = null,
        array $recordAttributes = [],
        array $detailAttributes = [],
    ): Record {
        $record = Record::factory()
            ->for($company ?? Company::factory())
            ->create($recordAttributes);

        RecordDetail::factory()->for($record)->create(array_merge([
            'operation_number' => 'OP-12345',
            'income' => '100.00',
            'commission_percentage' => '5.00',
            'commission' => '5.00',
            'return_amount' => '95.00',
            'order' => 1,
        ], $detailAttributes));

        return $record;
    }

    /**
     * @param  list<array<string, string>>|null  $details
     * @return array<string, mixed>
     */
    private function validManualRecordPayload(int $companyId, ?array $details = null): array
    {
        return [
            'company_id' => $companyId,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
            'affiliation' => 'AFF-123',
            'branch' => 'Guadalajara',
            'details' => $details ?? [[
                'operation_number' => 'OP-NEW',
                'income' => '100.00',
                'commission_percentage' => '5.00',
            ]],
        ];
    }
}
