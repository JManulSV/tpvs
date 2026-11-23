<?php

namespace Tests\Feature\Records;

use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AiProcessing;
use App\Models\History;
use App\Models\Image;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Concerns\BuildsManualRecords;
use Tests\TestCase;

class ConfirmRecordTest extends TestCase
{
    use BuildsManualRecords, RefreshDatabase;

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
}
