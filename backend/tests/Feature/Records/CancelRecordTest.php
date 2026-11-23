<?php

namespace Tests\Feature\Records;

use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AiProcessing;
use App\Models\History;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Concerns\BuildsManualRecords;
use Tests\TestCase;

class CancelRecordTest extends TestCase
{
    use BuildsManualRecords, RefreshDatabase;

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
}
