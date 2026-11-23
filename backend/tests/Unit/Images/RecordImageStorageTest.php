<?php

namespace Tests\Unit\Images;

use App\Domain\Images\RecordImageStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordImageStorageTest extends TestCase
{
    public function test_delete_quietly_removes_the_file_and_ignores_missing_ones(): void
    {
        Storage::fake('record_images');
        Storage::disk('record_images')->put('staged.png', 'png');
        $storage = app(RecordImageStorage::class);

        $storage->deleteQuietly('staged.png');
        $storage->deleteQuietly('never-existed.png');

        Storage::disk('record_images')->assertMissing('staged.png');
    }
}
