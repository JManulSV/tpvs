<?php

namespace Tests\Feature;

use App\Enums\AiProcessingStatus;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessImageJob;
use App\Models\AiProcessing;
use App\Models\Image;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordFromImageTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_invalid_upload_does_not_create_rows_file_or_job(): void
    {
        Storage::fake('record_images');
        Queue::fake();

        $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->post('/api/records/from-image', ['image' => UploadedFile::fake()->create('text.txt', 1, 'text/plain')])
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
            ->post('/api/records/from-image', [
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
