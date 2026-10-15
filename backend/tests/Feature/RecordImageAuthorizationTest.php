<?php

namespace Tests\Feature;

use App\Domain\Images\RecordImageStorage;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AiProcessing;
use App\Models\Image;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RecordImageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_routes_require_authentication(): void
    {
        $record = Record::factory()->create();

        $this->getJson('/api/records/'.$record->id.'/image')->assertUnauthorized();
        $this->postJson('/api/records/'.$record->id.'/image')->assertUnauthorized();
    }

    public function test_viewer_cannot_upload_an_image(): void
    {
        $record = Record::factory()->create();

        $this->actingAs(User::factory()->create(['role' => UserRole::VIEWER]), 'web')
            ->postJson('/api/records/'.$record->id.'/image')
            ->assertForbidden();
    }

    public function test_editor_uploads_an_image_without_changing_record_or_creating_ai_processing(): void
    {
        Storage::fake('record_images');
        $record = Record::factory()->create();
        $originalStatus = $record->status;

        $response = $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->post('/api/records/'.$record->id.'/image', [
                'image' => $this->pngUpload(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.original_file_name', 'image.png')
            ->assertJsonPath('data.mime_type', 'image/png');

        $image = Image::query()->where('record_id', $record->id)->firstOrFail();
        Storage::disk('record_images')->assertExists($image->file_path);
        $this->assertSame($originalStatus, $record->fresh()->status);
        $this->assertSame(0, AiProcessing::query()->count());
    }

    public function test_editor_can_replace_an_image_without_ai_attempts_and_old_file_is_deleted(): void
    {
        Storage::fake('record_images');
        $record = Record::factory()->create();
        Storage::disk('record_images')->put('old-image.png', $this->pngUpload()->getContent());
        $image = Image::factory()->for($record)->create(['file_path' => 'old-image.png']);

        $response = $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->post('/api/records/'.$record->id.'/image', [
                'image' => $this->pngUpload('replacement.png'),
            ]);

        $response->assertOk();
        $this->assertSame($image->id, $image->fresh()->id);
        $this->assertNotSame('old-image.png', $image->fresh()->file_path);
        Storage::disk('record_images')->assertMissing('old-image.png');
        Storage::disk('record_images')->assertExists($image->fresh()->file_path);
    }

    public function test_image_with_ai_attempts_cannot_be_replaced_and_uploaded_file_is_removed(): void
    {
        Storage::fake('record_images');
        $record = Record::factory()->create();
        Storage::disk('record_images')->put('processed-image.png', $this->pngUpload()->getContent());
        $image = Image::factory()->for($record)->create(['file_path' => 'processed-image.png']);
        AiProcessing::factory()->for($image)->create();

        $response = $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->post('/api/records/'.$record->id.'/image', [
                'image' => $this->pngUpload('replacement.png'),
            ]);

        $response->assertConflict()
            ->assertJsonPath('code', 'IMAGE_REPLACEMENT_BLOCKED')
            ->assertJsonPath('details.reason', 'AI_PROCESSING_EXISTS');
        $this->assertSame('processed-image.png', $image->fresh()->file_path);
        Storage::disk('record_images')->assertExists('processed-image.png');
        $this->assertCount(1, Storage::disk('record_images')->allFiles());
    }

    public function test_canceled_record_rejects_upload_before_storing_the_file(): void
    {
        Storage::fake('record_images');
        $record = Record::factory()->create(['status' => RecordStatus::CANCELED]);

        $response = $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->post('/api/records/'.$record->id.'/image', [
                'image' => $this->pngUpload(),
            ]);

        $response->assertConflict()
            ->assertJsonPath('code', 'IMAGE_UPLOAD_BLOCKED')
            ->assertJsonPath('details.reason', 'RECORD_CANCELED');
        $this->assertDatabaseMissing('images', ['record_id' => $record->id]);
        Storage::disk('record_images')->assertDirectoryEmpty('');
    }

    public function test_database_failure_cleans_up_the_staged_file(): void
    {
        Storage::fake('record_images');
        $record = Record::factory()->create();
        Event::listen('eloquent.creating: '.Image::class, static function (): void {
            throw new RuntimeException('Simulated image metadata persistence failure.');
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
                ->post('/api/records/'.$record->id.'/image', [
                    'image' => $this->pngUpload(),
                ]);
            $this->fail('Expected the simulated database operation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated image metadata persistence failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('images', ['record_id' => $record->id]);
        Storage::disk('record_images')->assertDirectoryEmpty('');
    }

    public function test_storage_failure_returns_a_safe_error_without_creating_metadata(): void
    {
        Storage::fake('record_images');
        $record = Record::factory()->create();
        $this->instance(
            RecordImageStorage::class,
            Mockery::mock(RecordImageStorage::class, static function ($mock): void {
                $mock->shouldReceive('store')
                    ->once()
                    ->andThrow(new RuntimeException('private/record-images/internal-path.png'));
            }),
        );

        $response = $this->actingAs(User::factory()->create(['role' => UserRole::EDITOR]), 'web')
            ->post('/api/records/'.$record->id.'/image', [
                'image' => $this->pngUpload(),
            ]);

        $response->assertStatus(500)
            ->assertJsonPath('message', 'The image could not be stored.')
            ->assertDontSee('private/record-images/internal-path.png');
        $this->assertDatabaseMissing('images', ['record_id' => $record->id]);
        Storage::disk('record_images')->assertDirectoryEmpty('');
    }

    public function test_editor_and_viewer_can_read_an_associated_image(): void
    {
        Storage::fake('record_images');
        $record = Record::factory()->create();
        $file = $this->pngUpload();
        Storage::disk('record_images')->put('existing.png', $file->getContent());
        Image::factory()->for($record)->create([
            'file_path' => 'existing.png',
            'mime_type' => 'image/png',
        ]);

        foreach ([UserRole::EDITOR, UserRole::VIEWER] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]), 'web')
                ->get('/api/records/'.$record->id.'/image');

            $response->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->assertHeader('X-Content-Type-Options', 'nosniff');

            $cacheControl = explode(', ', (string) $response->headers->get('Cache-Control'));
            $this->assertContains('private', $cacheControl);
            $this->assertContains('no-store', $cacheControl);
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
