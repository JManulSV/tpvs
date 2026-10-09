<?php

namespace Database\Factories;

use App\Models\Image;
use App\Models\Record;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
class ImageFactory extends Factory
{
    protected $model = Image::class;

    public function definition(): array
    {
        return [
            'record_id' => Record::factory(),
            'original_file_name' => fake()->word().'.jpg',
            'file_path' => 'records/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => fake()->numberBetween(1024, 5000000),
        ];
    }
}
