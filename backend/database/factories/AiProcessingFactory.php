<?php

namespace Database\Factories;

use App\Enums\AiProcessingStatus;
use App\Models\AiProcessing;
use App\Models\Image;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProcessing>
 */
class AiProcessingFactory extends Factory
{
    protected $model = AiProcessing::class;

    public function definition(): array
    {
        return [
            'image_id' => Image::factory(),
            'provider' => 'ollama',
            'model' => 'test-vision-model',
            'status' => AiProcessingStatus::COMPLETED,
            'result' => ['record' => null, 'details' => []],
            'error_message' => null,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ];
    }
}
