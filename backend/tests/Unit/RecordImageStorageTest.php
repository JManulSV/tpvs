<?php

namespace Tests\Unit;

use App\Domain\Images\RecordImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordImageStorageTest extends TestCase
{
    public function test_it_stores_a_content_detected_png_under_a_generated_private_key(): void
    {
        Storage::fake('record_images');
        $file = $this->pngUpload('supplier.png');

        $stored = app(RecordImageStorage::class)->store($file);

        $this->assertSame('image/png', $stored['mime_type']);
        $this->assertSame('supplier.png', $stored['original_file_name']);
        $this->assertSame($file->getSize(), $stored['file_size']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}\.png$/',
            $stored['file_path'],
        );
        Storage::disk('record_images')->assertExists($stored['file_path']);
    }

    public function test_it_strips_path_components_and_control_characters_from_the_original_name(): void
    {
        Storage::fake('record_images');
        $file = $this->pngUpload("..\\private\\supplier\n-copy.png");

        $stored = app(RecordImageStorage::class)->store($file);

        $this->assertSame('supplier-copy.png', $stored['original_file_name']);
    }

    public function test_it_rejects_content_that_is_not_an_allowed_image_mime(): void
    {
        Storage::fake('record_images');
        $file = UploadedFile::fake()->createWithContent('supplier.jpg', 'plain text, not an image');

        try {
            app(RecordImageStorage::class)->store($file);
            $this->fail('Expected unsupported content to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('image', $exception->errors());
        }

        Storage::disk('record_images')->assertDirectoryEmpty('');
    }

    public function test_it_rejects_empty_and_oversized_files(): void
    {
        Storage::fake('record_images');
        $storage = app(RecordImageStorage::class);

        foreach ([
            UploadedFile::fake()->createWithContent('empty.png', ''),
            UploadedFile::fake()->createWithContent('large.png', str_repeat('x', 10 * 1024 * 1024 + 1)),
        ] as $file) {
            try {
                $storage->store($file);
                $this->fail('Expected an invalid file size to be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('image', $exception->errors());
            }
        }

        Storage::disk('record_images')->assertDirectoryEmpty('');
    }

    private function pngUpload(string $name): UploadedFile
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j9s8AAAAASUVORK5CYII=',
            true,
        );

        $this->assertIsString($contents);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
