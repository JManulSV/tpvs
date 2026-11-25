<?php

namespace Tests\Feature\Records;

use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AiProcessing;
use App\Models\Company;
use App\Models\History;
use App\Models\Image;
use App\Models\RecordDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Concerns\BuildsManualRecords;
use Tests\TestCase;

class UpdateRecordTest extends TestCase
{
    use BuildsManualRecords, RefreshDatabase;

    public function test_editor_can_replace_manual_needs_review_record_and_logs_field_history(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();
        $newCompany = Company::factory()->create();
        $payload = $this->validManualRecordPayload($newCompany->id, [
            ['income' => '200.00'],
            ['income' => '50.00'],
        ], '10.00');
        $payload['details'][0]['affiliation'] = 'AFF-NEW-2';
        $payload['details'][0]['bank'] = 'BBVA';
        $payload['details'][1]['affiliation'] = 'AFF-NEW-1';
        $payload['details'][1]['bank'] = 'Banorte';
        $payload['reason'] = 'Se corrigieron importes y afiliación/banco';

        $response = $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload);

        $response->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.company.id', $newCompany->id)
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonMissingPath('data.affiliation')
            ->assertJsonMissingPath('data.branch')
            ->assertJsonCount(2, 'data.details')
            ->assertJsonPath('data.commission_percentage', '10.00')
            ->assertJsonPath('data.details.0.affiliation', 'AFF-NEW-2')
            ->assertJsonPath('data.details.0.bank', 'BBVA')
            ->assertJsonPath('data.details.0.order', 1)
            ->assertJsonPath('data.commission_total', '25.00')
            ->assertJsonPath('data.return_total', '225.00')
            ->assertJsonPath('data.details.1.affiliation', 'AFF-NEW-1')
            ->assertJsonPath('data.details.1.order', 2);

        // 250.00 × 10% = 25.00, recalculated over the new total in the same request.
        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'commission_percentage' => '10.00',
            'commission' => '25.00',
            'return_amount' => '225.00',
        ]);
        $this->assertDatabaseCount('record_details', 2);
        $this->assertDatabaseHas('record_details', ['record_id' => $record->id, 'order' => 1, 'bank' => 'BBVA']);
        $this->assertDatabaseHas('record_details', ['record_id' => $record->id, 'order' => 2, 'bank' => 'Banorte']);
        $this->assertDatabaseMissing('histories', [
            'record_id' => $record->id,
            'field_name' => 'record_updated',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'company',
            'previous_value' => $record->company->name,
            'new_value' => $newCompany->name,
            'reason' => 'Se corrigieron importes y afiliación/banco',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'commission_percentage',
            'previous_value' => '5.00',
            'new_value' => '10.00',
            'reason' => 'Se corrigieron importes y afiliación/banco',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'details[1].bank',
            'previous_value' => 'LUUM',
            'new_value' => 'BBVA',
            'reason' => 'Se corrigieron importes y afiliación/banco',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'details[1].income',
            'previous_value' => '100.00',
            'new_value' => '200.00',
            'reason' => 'Se corrigieron importes y afiliación/banco',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'details[2].order',
            'previous_value' => null,
            'new_value' => '2',
            'reason' => 'Se corrigieron importes y afiliación/banco',
        ]);
    }

    public function test_manual_needs_review_update_without_changes_logs_single_record_updated(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, [
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ], ['affiliation' => 'AFF-123']);
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['reason'] = 'Sin cambios relevantes';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.details.0.affiliation', 'AFF-123');

        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'record_updated',
            'previous_value' => null,
            'new_value' => null,
            'reason' => 'Sin cambios relevantes',
        ]);
        $this->assertDatabaseCount('record_details', 1);
    }

    public function test_manual_update_logs_removed_detail_fields_as_null(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, [
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ], ['affiliation' => 'AFF-123']);
        RecordDetail::factory()->for($record)->create([
            'order' => 2,
            'affiliation' => 'AFF-REMOVED',
            'bank' => 'Banorte',
            'income' => '50.00',
        ]);
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['reason'] = 'Se eliminó un movimiento';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonCount(1, 'data.details')
            ->assertJsonPath('data.details.0.affiliation', 'AFF-123');

        $this->assertDatabaseCount('record_details', 1);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'details[2].order',
            'previous_value' => '2',
            'new_value' => null,
            'reason' => 'Se eliminó un movimiento',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'details[2].income',
            'previous_value' => '50.00',
            'new_value' => null,
            'reason' => 'Se eliminó un movimiento',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'details[2].affiliation',
            'previous_value' => 'AFF-REMOVED',
            'new_value' => null,
            'reason' => 'Se eliminó un movimiento',
        ]);
        $this->assertDatabaseMissing('histories', [
            'record_id' => $record->id,
            'field_name' => 'details[1].income',
        ]);
    }

    public function test_editor_can_reopen_confirmed_record_and_logs_status_and_field_changes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, [
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ], ['affiliation' => 'AFF-123']);
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['details'][0]['income'] = '120.00';
        $payload['reason'] = 'Se corrigió el ingreso';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.source', RecordSource::MANUAL->value)
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.details.0.income', '120.00')
            ->assertJsonPath('data.commission_total', '6.00')
            ->assertJsonPath('data.return_total', '114.00');

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'status' => RecordStatus::NEEDS_REVIEW->value,
            'commission' => '6.00',
            'return_amount' => '114.00',
        ]);
        // The derived commission and return never get their own events.
        foreach (['commission', 'return_amount', 'details[1].commission', 'details[1].return_amount'] as $derived) {
            $this->assertDatabaseMissing('histories', ['record_id' => $record->id, 'field_name' => $derived]);
        }
        foreach ([
            ['field_name' => 'details[1].income', 'previous_value' => '100.00', 'new_value' => '120.00'],
            ['field_name' => 'status', 'previous_value' => RecordStatus::CONFIRMED->value, 'new_value' => RecordStatus::NEEDS_REVIEW->value],
        ] as $expected) {
            $this->assertDatabaseHas('histories', [
                'record_id' => $record->id,
                'user_id' => $editor->id,
                'reason' => 'Se corrigió el ingreso',
            ] + $expected);
        }

        $this->actingAs($editor, 'web')
            ->postJson('/api/records/'.$record->id.'/confirm', [])
            ->assertOk()
            ->assertJsonPath('data.status', RecordStatus::CONFIRMED->value);

        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'status',
            'previous_value' => RecordStatus::NEEDS_REVIEW->value,
            'new_value' => RecordStatus::CONFIRMED->value,
            'reason' => 'Registro manual confirmado',
        ]);
    }

    public function test_reopening_confirmed_image_record_preserves_source_image_and_ai_evidence(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, [
            'source' => RecordSource::IMAGE,
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ], ['affiliation' => 'AFF-123']);
        $image = Image::factory()->for($record)->create();
        $processing = AiProcessing::factory()->for($image)->create([
            'status' => AiProcessingStatus::NEEDS_REVIEW,
            'result' => [
                'normalized_extraction' => [
                    'record' => ['company_name' => 'Acme'],
                    'details' => [],
                ],
                'issues' => [[
                    'path' => 'details.0.bank',
                    'code' => 'BANK_MISSING',
                    'severity' => 'review',
                    'message' => 'Bank could not be read.',
                ]],
                'unconvertible_numeric_count' => 1,
            ],
        ]);
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['details'][0]['bank'] = 'BBVA';
        $payload['reason'] = 'Se corrigió el banco';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.source', RecordSource::IMAGE->value)
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.image.id', $image->id)
            ->assertJsonPath('data.ai_processing.status', AiProcessingStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.ai_processing.issues.0.code', 'BANK_MISSING');

        $this->assertDatabaseCount('images', 1);
        $this->assertDatabaseCount('ai_processings', 1);
        $this->assertSame($image->id, Image::query()->sole()->id);
        $this->assertSame($processing->id, AiProcessing::query()->sole()->id);
        $this->assertSame($processing->result, $processing->fresh()->result);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'details[1].bank',
            'previous_value' => 'LUUM',
            'new_value' => 'BBVA',
            'reason' => 'Se corrigió el banco',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'status',
            'previous_value' => RecordStatus::CONFIRMED->value,
            'new_value' => RecordStatus::NEEDS_REVIEW->value,
            'reason' => 'Se corrigió el banco',
        ]);
    }

    public function test_reopening_confirmed_record_without_changes_is_rejected_without_writes(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, [
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ], ['affiliation' => 'AFF-123']);
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['reason'] = 'Intento sin cambios';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'RECORD_UPDATE_NO_CHANGES')
            ->assertJsonPath('details.reason', 'NO_CHANGES');

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'status' => RecordStatus::CONFIRMED->value,
        ]);
        $this->assertDatabaseCount('record_details', 1);
        $this->assertDatabaseHas('record_details', [
            'record_id' => $record->id,
            'income' => '100.00',
        ]);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_failed_history_write_rolls_back_confirmed_record_reopen(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, [
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ], ['affiliation' => 'AFF-123']);
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['details'][0]['income'] = '120.00';
        $payload['reason'] = 'Test rollback de reapertura';

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
            'status' => RecordStatus::CONFIRMED->value,
        ]);
        $this->assertDatabaseCount('record_details', 1);
        $this->assertDatabaseHas('record_details', [
            'record_id' => $record->id,
            'income' => '100.00',
        ]);
        $this->assertDatabaseCount('histories', 0);
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
                    'path' => 'details.0.bank',
                    'code' => 'BANK_MISSING',
                    'severity' => 'review',
                    'message' => 'Bank could not be read.',
                ]],
                'unconvertible_numeric_count' => 1,
            ],
        ]);
        $newCompany = Company::factory()->create();
        $payload = $this->validManualRecordPayload($newCompany->id, [[
            'income' => '200.00',
        ]], '10.00');
        $payload['details'][0]['affiliation'] = 'AFF-IMAGE-FIXED';
        $payload['details'][0]['bank'] = 'BBVA';
        $payload['reason'] = 'Se corrigió el banco mal leído';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.source', RecordSource::IMAGE->value)
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.company.id', $newCompany->id)
            ->assertJsonPath('data.image.id', $image->id)
            ->assertJsonPath('data.ai_processing.status', AiProcessingStatus::NEEDS_REVIEW->value)
            ->assertJsonPath('data.ai_processing.issues.0.code', 'BANK_MISSING')
            ->assertJsonPath('data.income_total', '200.00');

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'commission' => '20.00',
            'return_amount' => '180.00',
        ]);

        $this->assertDatabaseCount('images', 1);
        $this->assertDatabaseCount('ai_processings', 1);
        $this->assertSame($image->id, Image::query()->sole()->id);
        $this->assertSame($processing->id, AiProcessing::query()->sole()->id);
        $this->assertSame($processing->result, $processing->fresh()->result);
        $this->assertDatabaseMissing('histories', [
            'record_id' => $record->id,
            'field_name' => 'record_updated',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'details[1].bank',
            'previous_value' => 'LUUM',
            'new_value' => 'BBVA',
            'reason' => 'Se corrigió el banco mal leído',
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
                'commission_percentage', 'details', 'reason',
            ]);

        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['reason'] = '   ';
        $payload['details'][0]['income'] = '20.00';

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

    public function test_record_replacement_requires_the_global_commission_percentage(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['reason'] = 'Sin porcentaje';
        unset($payload['commission_percentage']);

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commission_percentage']);

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_record_replacement_rejects_legacy_and_derived_commission_fields(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();
        $payload = $this->validManualRecordPayload($record->company_id);
        $payload['reason'] = 'Campos antiguos';

        foreach (['commission' => '5.00', 'return_amount' => '95.00'] as $field => $value) {
            $this->actingAs($editor, 'web')
                ->putJson('/api/records/'.$record->id, array_merge($payload, [$field => $value]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
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
                ->putJson('/api/records/'.$record->id, $invalidPayload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(["details.0.{$field}"]);
        }

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_record_replacement_rejects_a_total_income_that_does_not_fit_the_record(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();
        $payload = $this->validManualRecordPayload($record->company_id, [
            ['income' => '9999999999.99'],
            ['income' => '0.01'],
        ]);
        $payload['reason'] = 'Total fuera de rango';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details']);

        $this->assertDatabaseCount('record_details', 1);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_changing_only_the_percentage_recalculates_and_logs_the_percentage(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails(null, [
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
        ], ['affiliation' => 'AFF-123']);
        $payload = $this->validManualRecordPayload($record->company_id, null, '6.50');
        $payload['reason'] = 'Se corrigió el porcentaje';

        $this->actingAs($editor, 'web')
            ->putJson('/api/records/'.$record->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.status', RecordStatus::NEEDS_REVIEW->value);

        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'commission_percentage' => '6.50',
            'commission' => '6.50',
            'return_amount' => '93.50',
        ]);
        $this->assertDatabaseHas('histories', [
            'record_id' => $record->id,
            'user_id' => $editor->id,
            'field_name' => 'commission_percentage',
            'previous_value' => '5.00',
            'new_value' => '6.50',
            'reason' => 'Se corrigió el porcentaje',
        ]);
        foreach (['commission', 'return_amount'] as $derived) {
            $this->assertDatabaseMissing('histories', ['record_id' => $record->id, 'field_name' => $derived]);
        }
    }

    public function test_records_in_blocked_states_cannot_be_replaced(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $statuses = [
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
        $this->assertDatabaseCount('record_details', 4);
    }

    public function test_failed_history_write_rolls_back_record_and_detail_replacement(): void
    {
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $record = $this->createRecordWithDetails();
        $payload = $this->validManualRecordPayload($record->company_id, [[
            'income' => '500.00',
        ]], '10.00');
        $payload['details'][0]['affiliation'] = 'AFF-ROLLBACK';
        $payload['details'][0]['bank'] = 'Should roll back';
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
            'affiliation' => 'AFF-12345',
            'bank' => 'LUUM',
        ]);
        $this->assertDatabaseHas('records', [
            'id' => $record->id,
            'commission_percentage' => '5.00',
            'commission' => '5.00',
            'return_amount' => '95.00',
        ]);
        $this->assertDatabaseCount('histories', 0);
    }
}
