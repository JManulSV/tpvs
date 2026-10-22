<?php

namespace Tests\Feature;

use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AiProcessing;
use App\Models\Company;
use App\Models\History;
use App\Models\Image;
use App\Models\Record;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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
                ->assertJsonPath('data.0.details.0.operation_number', 'OP-12345')
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

    public function test_list_supports_case_insensitive_search_across_record_fields(): void
    {
        $company = Company::factory()->create(['name' => 'Northwind Supply']);
        $record = $this->createRecordWithDetails($company, [], [
            'affiliation' => 'AFF-928',
            'branch' => 'Guadalajara Centro',
            'operation_number' => 'OP-74531',
        ]);
        RecordDetail::factory()->for($record)->create([
            'operation_number' => 'OP-SECOND',
            'affiliation' => 'AFF-SECOND',
            'branch' => 'Tonalá',
            'order' => 2,
        ]);
        $other = $this->createRecordWithDetails(Company::factory()->create(['name' => 'Contoso']));

        foreach ([' NORTHWIND ', 'op-745', 'aff-second', 'TONALÁ'] as $search) {
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
            '/api/records?year=2025&week=54',
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
                    'affiliation' => 'AFF-FIRST',
                    'branch' => 'Guadalajara',
                    'income' => '0.10',
                    'commission_percentage' => '5.00',
                ],
                [
                    'operation_number' => 'OP-FIXED',
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
            'field_name' => 'status',
            'previous_value' => null,
            'new_value' => RecordStatus::CONFIRMED->value,
            'reason' => 'Registro manual confirmado',
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
                'operation_number' => 'OP-INVALID',
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
        $payload['details'][0]['affiliation'] = 'AFF-NEW-2';
        $payload['details'][0]['branch'] = 'Zapopan';
        $payload['details'][1]['affiliation'] = 'AFF-NEW-1';
        $payload['details'][1]['branch'] = 'Tlaquepaque';
        $payload['reason'] = 'Se corrigieron importes y afiliación/sucursal';

        $response = $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload);

        $response->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.company.id', $newCompany->id)
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonMissingPath('data.affiliation')
            ->assertJsonMissingPath('data.branch')
            ->assertJsonCount(2, 'data.details')
            ->assertJsonPath('data.details.0.operation_number', 'OP-NEW-2')
            ->assertJsonPath('data.details.0.affiliation', 'AFF-NEW-2')
            ->assertJsonPath('data.details.0.branch', 'Zapopan')
            ->assertJsonPath('data.details.0.order', 1)
            ->assertJsonPath('data.details.0.commission', '20.00')
            ->assertJsonPath('data.details.1.operation_number', 'OP-NEW-1')
            ->assertJsonPath('data.details.1.affiliation', 'AFF-NEW-1')
            ->assertJsonPath('data.details.1.branch', 'Tlaquepaque')
            ->assertJsonPath('data.details.1.order', 2)
            ->assertJsonPath('data.details.1.return_amount', '45.00');

        $this->assertDatabaseCount('record_details', 2);
        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'record_updated',
            'previous_value' => null,
            'new_value' => null,
            'reason' => 'Se corrigieron importes y afiliación/sucursal',
        ]);
    }

    public function test_editor_can_replace_image_needs_review_record_preserving_image_and_ai_evidence(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, ['source' => RecordSource::IMAGE]);
        $image = Image::factory()->for($record)->create();
        $processing = AiProcessing::factory()->for($image)->create([
            'status' => AiProcessingStatus::NEEDS_REVIEW,
            'result' => [
                'normalized_extraction' => [
                    'record' => ['company_name' => 'Acme'],
                    'details' => [],
                ],
                'issues' => [[
                    'path' => 'details.0.branch',
                    'code' => 'BRANCH_MISSING',
                    'severity' => 'review',
                    'message' => 'Branch could not be read.',
                ]],
                'unconvertible_numeric_count' => 1,
            ],
        ]);
        $newCompany = Company::factory()->create();
        $payload = $this->validManualRecordPayload($newCompany->id, [[
            'operation_number' => 'OP-IMAGE-FIXED',
            'income' => '200.00',
            'commission_percentage' => '10.00',
        ]]);
        $payload['details'][0]['affiliation'] = 'AFF-IMAGE-FIXED';
        $payload['details'][0]['branch'] = 'Zapopan';
        $payload['reason'] = 'Se corrigió la sucursal mal leída';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.source', RecordSource::IMAGE->value)
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.company.id', $newCompany->id)
            ->assertJsonPath('data.image.id', $image->id)
            ->assertJsonPath('data.ai_processing.status', AiProcessingStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.ai_processing.issues.0.code', 'BRANCH_MISSING')
            ->assertJsonPath('data.details.0.operation_number', 'OP-IMAGE-FIXED')
            ->assertJsonPath('data.income_total', '200.00')
            ->assertJsonPath('data.commission_total', '20.00')
            ->assertJsonPath('data.return_total', '180.00');

        $this->assertDatabaseCount('images', 1);
        $this->assertDatabaseCount('ai_processings', 1);
        $this->assertSame($image->id, Image::query()->sole()->id);
        $this->assertSame($processing->id, AiProcessing::query()->sole()->id);
        $this->assertSame($processing->result, $processing->fresh()->result);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'record_updated',
            'previous_value' => null,
            'new_value' => null,
            'reason' => 'Se corrigió la sucursal mal leída',
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
                'details', 'reason',
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

    public function test_record_replacement_rejects_affiliation_and_branch_at_the_root(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['reason'] = 'Legacy payload fields must be rejected';
        $payload['affiliation'] = 'AFF-ROOT';
        $payload['branch'] = 'Root branch';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['affiliation', 'branch']);

        $this->assertDatabaseCount('histories', 0);
        $this->assertDatabaseCount('record_details', 1);
    }

    public function test_only_records_needing_review_can_be_replaced(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $statuses = [
            RecordStatus::CONFIRMED,
            RecordStatus::CANCELED,
            RecordStatus::PENDING,
            RecordStatus::PROCESSING,
            RecordStatus::ERROR,
        ];

        foreach ($statuses as $status) {
            $record = $this->createRecordWithDetails(null, ['status' => $status]);
            $payload = $this->validManualRecordPayload($record->company_id);
            $payload['reason'] = 'Attempted edit';

            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id, $payload)
                ->assertStatus(409)
                ->assertJsonPath('code', 'RECORD_UPDATE_BLOCKED')
                ->assertJsonPath('details.reason', 'INVALID_STATE');
        }

        $this->assertDatabaseCount('histories', 0);
        $this->assertDatabaseCount('record_details', 5);
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
        $payload['details'][0]['affiliation'] = 'AFF-ROLLBACK';
        $payload['details'][0]['branch'] = 'Should roll back';
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

        $this->assertDatabaseCount('record_details', 1);
        $this->assertDatabaseHas('record_details', [
            'record_id' => $record->id,
            'operation_number' => 'OP-12345',
            'affiliation' => 'AFF-12345',
            'branch' => 'Guadalajara',
        ]);
        $this->assertDatabaseCount('histories', 0);
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

    public function test_editor_can_confirm_valid_manual_record_with_status_history(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/confirm', [])
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.source', RecordSource::MANUAL->value)
            ->assertJsonPath('data.status', RecordStatus::CONFIRMED->value)
            ->assertJsonPath('data.return_total', '95.00');

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'status' => RecordStatus::CONFIRMED->value,
        ]);
        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'status',
            'previous_value' => RecordStatus::NEEDS_REVIEW->value,
            'new_value' => RecordStatus::CONFIRMED->value,
            'reason' => 'Registro manual confirmado',
        ]);
    }

    public function test_editor_can_confirm_image_record_with_unresolved_ai_issues(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, ['source' => RecordSource::IMAGE]);
        $image = Image::factory()->for($record)->create();
        $processing = AiProcessing::factory()->for($image)->create([
            'status' => AiProcessingStatus::NEEDS_REVIEW,
            'result' => [
                'normalized_extraction' => [
                    'record' => ['company_name' => 'Acme'],
                    'details' => [],
                ],
                'issues' => [[
                    'path' => 'record.company_name',
                    'code' => 'COMPANY_NAME_UNVERIFIED',
                    'severity' => 'review',
                    'message' => 'Company name should be reviewed.',
                ]],
                'unconvertible_numeric_count' => 0,
            ],
        ]);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/confirm', [])
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.source', RecordSource::IMAGE->value)
            ->assertJsonPath('data.status', RecordStatus::CONFIRMED->value)
            ->assertJsonPath('data.ai_processing.status', AiProcessingStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.ai_processing.issues.0.code', 'COMPANY_NAME_UNVERIFIED');

        $this->assertDatabaseCount('images', 1);
        $this->assertDatabaseCount('ai_processings', 1);
        $this->assertSame($processing->result, $processing->fresh()->result);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'status',
            'previous_value' => RecordStatus::NEEDS_REVIEW->value,
            'new_value' => RecordStatus::CONFIRMED->value,
            'reason' => 'Registro de imagen confirmado',
        ]);
    }

    public function test_confirmation_requires_authentication_and_editor_role(): void
    {
        $record = $this->createRecordWithDetails();

        $this->postJson('/api/records/'.$record->id.'/confirm', [])->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => UserRole::VIEWER]), 'web')
            ->postJson('/api/records/'.$record->id.'/confirm', [])
            ->assertForbidden();

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_confirmation_rejects_invalid_state_and_incomplete_image_source(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $confirmed = $this->createRecordWithDetails(null, ['status' => RecordStatus::CONFIRMED]);
        $imageRecord = $this->createRecordWithDetails(null, ['source' => RecordSource::IMAGE]);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$confirmed->id.'/confirm', [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RECORD_CONFIRMATION_BLOCKED')
            ->assertJsonPath('details.reason', 'INVALID_STATE');

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$imageRecord->id.'/confirm', [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RECORD_CONFIRMATION_BLOCKED')
            ->assertJsonPath('details.reason', 'BUSINESS_VALIDATION_FAILED');

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_confirmation_revalidates_stored_financial_calculations(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();
        $record->details()->firstOrFail()->update([
            'commission' => '6.00',
            'return_amount' => '94.00',
        ]);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/confirm', [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RECORD_CONFIRMATION_BLOCKED')
            ->assertJsonPath('details.reason', 'BUSINESS_VALIDATION_FAILED');

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_confirmation_rejects_provisional_image_record_with_missing_business_fields(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = Record::factory()->create([
            'company_id' => null,
            'source' => RecordSource::IMAGE,
            'status' => RecordStatus::NEEDS_REVIEW,
            'year' => null,
            'week' => null,
            'week_start_date' => null,
            'week_end_date' => null,
        ]);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/confirm', [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RECORD_CONFIRMATION_BLOCKED')
            ->assertJsonPath('details.reason', 'BUSINESS_VALIDATION_FAILED');

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_confirmation_rejects_image_record_with_inconsistent_calculations_without_history(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, ['source' => RecordSource::IMAGE]);
        $record->details()->firstOrFail()->update([
            'commission' => '1.00',
            'return_amount' => '99.00',
        ]);
        $image = Image::factory()->for($record)->create();
        AiProcessing::factory()->for($image)->create();

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/confirm', [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RECORD_CONFIRMATION_BLOCKED')
            ->assertJsonPath('details.reason', 'BUSINESS_VALIDATION_FAILED');

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_confirmation_does_not_accept_client_control_of_state_or_reason(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/confirm', [
                'status' => RecordStatus::CONFIRMED->value,
                'reason' => 'Client supplied reason',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'reason']);

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_failed_confirmation_history_write_rolls_back_status(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();

        Event::listen('eloquent.creating: '.History::class, static function (): void {
            throw new \RuntimeException('Simulated history persistence failure.');
        });

        try {
            $this->actingAs($editor, 'web')
                ->postJson('/api/records/'.$record->id.'/confirm', [])
                ->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.History::class);
        }

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'status' => RecordStatus::NEEDS_REVIEW->value,
        ]);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_record_cancellation_requires_authentication_and_editor_role(): void
    {
        $record = $this->createRecordWithDetails();

        $this->postJson('/api/records/'.$record->id.'/cancel', [
            'reason' => 'Duplicated record',
        ])->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => UserRole::VIEWER]), 'web')
            ->postJson('/api/records/'.$record->id.'/cancel', [
                'reason' => 'Duplicated record',
            ])
            ->assertForbidden();

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_editor_can_cancel_needs_review_record_and_preserves_image_and_ai_evidence(): void
    {
        Storage::fake('record_images');
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, ['source' => RecordSource::IMAGE]);
        Storage::disk('record_images')->put('reviewed.png', 'image-bytes');
        $image = Image::factory()->for($record)->create(['file_path' => 'reviewed.png']);
        $processing = AiProcessing::factory()->for($image)->create([
            'status' => AiProcessingStatus::NEEDS_REVIEW,
            'result' => [
                'normalized_extraction' => ['record' => ['company_name' => 'Acme']],
                'issues' => [[
                    'path' => 'record.company_name',
                    'code' => 'COMPANY_NAME_UNVERIFIED',
                    'severity' => 'review',
                    'message' => 'Company name should be reviewed.',
                ]],
                'unconvertible_numeric_count' => 0,
            ],
        ]);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/cancel', [
                'reason' => '  Cancelado por duplicado  ',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.source', RecordSource::IMAGE->value)
            ->assertJsonPath('data.status', RecordStatus::CANCELED->value)
            ->assertJsonPath('data.image.id', $image->id)
            ->assertJsonPath('data.ai_processing.status', AiProcessingStatus::NEEDS_REVIEW->value);

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'status' => RecordStatus::CANCELED->value,
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'status',
            'previous_value' => RecordStatus::NEEDS_REVIEW->value,
            'new_value' => RecordStatus::CANCELED->value,
            'reason' => 'Cancelado por duplicado',
        ]);
        $this->assertDatabaseCount('images', 1);
        $this->assertDatabaseCount('ai_processings', 1);
        $this->assertSame($processing->result, $processing->fresh()->result);
        Storage::disk('record_images')->assertExists('reviewed.png');

        $this->actingAs($editor, 'web')
            ->getJson('/api/records/'.$record->id.'/image')
            ->assertConflict()
            ->assertJsonPath('code', 'IMAGE_ACCESS_BLOCKED')
            ->assertJsonPath('details.reason', 'RECORD_CANCELED');
    }

    public function test_editor_can_cancel_error_record_and_previous_status_is_recorded(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, ['status' => RecordStatus::ERROR]);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/cancel', [
                'reason' => 'AI failed definitively',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', RecordStatus::CANCELED->value);

        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'field_name' => 'status',
            'previous_value' => RecordStatus::ERROR->value,
            'new_value' => RecordStatus::CANCELED->value,
            'reason' => 'AI failed definitively',
        ]);
    }

    public function test_cancellation_rejects_other_states_without_writes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $statuses = [
            RecordStatus::CONFIRMED,
            RecordStatus::PENDING,
            RecordStatus::PROCESSING,
            RecordStatus::CANCELED,
        ];

        foreach ($statuses as $status) {
            $record = $this->createRecordWithDetails(null, ['status' => $status]);

            $this->actingAs($editor, 'web')
                ->postJson('/api/records/'.$record->id.'/cancel', [
                    'reason' => 'Attempted cancellation',
                ])
                ->assertStatus(409)
                ->assertJsonPath('code', 'RECORD_CANCELLATION_BLOCKED')
                ->assertJsonPath('details.reason', 'INVALID_STATE');

            $this->assertSame($status, $record->fresh()->status);
        }

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_cancellation_requires_reason_and_rejects_client_control_fields(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/cancel', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/cancel', ['reason' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/cancel', [
                'reason' => 'Valid reason',
                'source' => RecordSource::MANUAL->value,
                'status' => RecordStatus::CONFIRMED->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source', 'status']);

        $this->assertSame(RecordStatus::NEEDS_REVIEW, $record->fresh()->status);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_failed_cancellation_history_write_rolls_back_status(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();

        Event::listen('eloquent.creating: '.History::class, static function (): void {
            throw new \RuntimeException('Simulated history persistence failure.');
        });

        try {
            $this->actingAs($editor, 'web')
                ->postJson('/api/records/'.$record->id.'/cancel', [
                    'reason' => 'Test rollback',
                ])
                ->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.History::class);
        }

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'status' => RecordStatus::NEEDS_REVIEW->value,
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
            'affiliation' => 'AFF-12345',
            'branch' => 'Guadalajara',
        ], $detailAttributes));

        return $record;
    }

    /**
     * @param  list<array<string, string>>|null  $details
     * @return array<string, mixed>
     */
    private function validManualRecordPayload(int $companyId, ?array $details = null): array
    {
        $details ??= [[
            'operation_number' => 'OP-NEW',
            'income' => '100.00',
            'commission_percentage' => '5.00',
        ]];

        return [
            'company_id' => $companyId,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
            'details' => array_map(static fn (array $detail, int $index): array => array_merge([
                'affiliation' => 'AFF-'.($index + 123),
                'branch' => 'Guadalajara',
            ], $detail), $details, array_keys($details)),
        ];
    }
}
