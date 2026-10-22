<?php

namespace Tests\Feature;

use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessImageJob;
use App\Models\AiProcessing;
use App\Models\Company;
use App\Models\Image;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EnforceCsrfToken;
use Tests\TestCase;

class RecordFromImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.middleware.validate_csrf_token' => EnforceCsrfToken::class]);
    }

    public function test_editor_creates_a_provisional_image_record_and_dispatches_after_creation(): void
    {
        Storage::fake('record_images');
        Queue::fake();
        config(['queue.default' => 'database']);

        $response = $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->post('/api/records/from-image', ['image' => $this->pngUpload()]);

        $response->assertStatus(202)
            ->assertJsonPath('data.source', RecordSource::IMAGE->value)
            ->assertJsonPath('data.status', RecordStatus::PENDING->value)
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.image.mime_type', 'image/png');

        $record = Record::query()->firstOrFail();
        $image = Image::query()->where('record_id', $record->id)->firstOrFail();
        $processing = AiProcessing::query()->where('image_id', $image->id)->firstOrFail();

        $this->assertSame(RecordStatus::PENDING, $record->status);
        $this->assertSame(AiProcessingStatus::PENDING, $processing->status);
        $this->assertNull($record->company_id);
        Storage::disk('record_images')->assertExists($image->file_path);
        Queue::assertPushed(ProcessImageJob::class, fn (ProcessImageJob $job): bool => $job->aiProcessingId === $processing->id);
    }

    public function test_viewer_cannot_create_an_image_record(): void
    {
        Storage::fake('record_images');

        $this->actingAs(User::factory()->create(['role' => UserRole::VIEWER]), 'web')
            ->post('/api/records/from-image', ['image' => $this->pngUpload()])
            ->assertForbidden();

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('ai_processings', 0);
        Storage::disk('record_images')->assertDirectoryEmpty('');
    }

    public function test_anonymous_editor_endpoint_request_is_unauthorized(): void
    {
        $this->postJson('/api/records/from-image', [])->assertUnauthorized();
    }

    public function test_stateful_editor_upload_requires_valid_csrf_token(): void
    {
        Storage::fake('record_images');
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);
        $token = 'valid-image-upload-csrf';

        $this->withSession(['_token' => $token])
            ->actingAs($editor, 'web')
            ->post('/api/records/from-image', ['image' => $this->pngUpload()], [
                'Origin' => 'http://localhost:3000',
                'X-CSRF-TOKEN' => 'invalid-csrf-token',
            ])
            ->assertStatus(419);

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('ai_processings', 0);
        Storage::disk('record_images')->assertDirectoryEmpty('');
    }

    public function test_invalid_upload_does_not_create_rows_file_or_job(): void
    {
        Storage::fake('record_images');
        Queue::fake();

        $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->postJson('/api/records/from-image', ['image' => UploadedFile::fake()->create('text.txt', 1, 'text/plain')])
            ->assertUnprocessable();

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('ai_processings', 0);
        Storage::disk('record_images')->assertDirectoryEmpty('');
        Queue::assertNothingPushed();
    }

    public function test_client_cannot_supply_server_controlled_record_or_financial_fields(): void
    {
        Storage::fake('record_images');
        Queue::fake();

        $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->postJson('/api/records/from-image', [
                'image' => $this->pngUpload(),
                'company_id' => 1,
                'status' => 'CONFIRMED',
                'income' => '1.00',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('records', 0);
        Storage::disk('record_images')->assertDirectoryEmpty('');
        Queue::assertNothingPushed();
    }

    public function test_database_failure_removes_the_staged_image_file(): void
    {
        Storage::fake('record_images');
        Queue::fake();
        Event::listen('eloquent.creating: '.Image::class, static function (): void {
            throw new \RuntimeException('simulated metadata failure');
        });

        $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->post('/api/records/from-image', ['image' => $this->pngUpload()])
            ->assertStatus(500)
            ->assertDontSee('simulated metadata failure');

        $this->assertDatabaseCount('records', 0);
        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('ai_processings', 0);
        Storage::disk('record_images')->assertDirectoryEmpty('');
        Queue::assertNothingPushed();
    }

    public function test_dispatch_failure_after_commit_marks_record_and_processing_error(): void
    {
        Storage::fake('record_images');
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'not-a-real-queue-driver',
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->postJson('/api/records/from-image', ['image' => $this->pngUpload()])
            ->assertStatus(202)
            ->assertJsonPath('data.status', RecordStatus::ERROR->value)
            ->assertJsonPath('data.ai_processing.status', AiProcessingStatus::ERROR->value)
            ->assertJsonPath('data.ai_processing.issues.0.code', 'AI_JOB_DISPATCH_FAILED')
            ->assertDontSee('not-a-real-queue-driver');

        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('images', 1);
        $this->assertDatabaseCount('ai_processings', 1);
        $this->assertDatabaseHas('ai_processings', ['error_message' => 'AI_JOB_DISPATCH_FAILED']);
    }

    public function test_resource_does_not_serialize_raw_provider_response(): void
    {
        Storage::fake('record_images');
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::EDITOR]);
        $company = Company::factory()->create(['name' => 'Example Co']);
        $this->actingAs($user, 'web')->postJson('/api/records/from-image', ['image' => $this->pngUpload()])->assertAccepted();

        $record = Record::query()->with(['image.aiProcessing'])->firstOrFail();
        $record->image->aiProcessing->forceFill([
            'status' => AiProcessingStatus::COMPLETED,
            'result' => [
                'raw_response' => 'secret provider raw response',
                'normalized_extraction' => ['record' => ['company_name' => ['value' => $company->name, 'confidence' => 0.9]]],
                'issues' => [],
                'unconvertible_numeric_count' => 0,
            ],
        ])->save();

        $this->getJson('/api/records/'.$record->id)
            ->assertOk()
            ->assertJsonPath('data.ai_processing.status', AiProcessingStatus::COMPLETED->value)
            ->assertJsonMissingPath('data.ai_processing.raw_response')
            ->assertDontSee('secret provider raw response');
    }

    public function test_queue_dispatch_is_deferred_until_the_outer_database_transaction_commits(): void
    {
        Storage::fake('record_images');
        config(['queue.default' => 'database']);
        $editor = User::factory()->create(['role' => UserRole::EDITOR]);

        DB::beginTransaction();
        try {
            $response = $this->actingAs($editor, 'web')
                ->postJson('/api/records/from-image', ['image' => $this->pngUpload()]);

            $response->assertAccepted();
            $this->assertDatabaseCount('jobs', 0);

            DB::commit();
            $this->assertDatabaseCount('jobs', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function pngUpload(): UploadedFile
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j9s8AAAAASUVORK5CYII=',
            true,
        );
        $this->assertIsString($contents);

        return UploadedFile::fake()->createWithContent('image.png', $contents);
    }
}
