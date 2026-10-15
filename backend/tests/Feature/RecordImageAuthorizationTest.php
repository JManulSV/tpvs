<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Image;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
