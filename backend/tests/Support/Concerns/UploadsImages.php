<?php

namespace Tests\Support\Concerns;

use Illuminate\Http\UploadedFile;

trait UploadsImages
{
    /** A real 1×1 PNG, so content sniffing accepts it. */
    protected function pngUpload(string $name = 'image.png'): UploadedFile
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j9s8AAAAASUVORK5CYII=',
            true,
        );

        $this->assertIsString($contents);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
