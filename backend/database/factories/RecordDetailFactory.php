<?php

namespace Database\Factories;

use App\Models\Record;
use App\Models\RecordDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordDetail>
 */
class RecordDetailFactory extends Factory
{
    protected $model = RecordDetail::class;

    public function definition(): array
    {
        return [
            'record_id' => Record::factory(),
            'affiliation' => fake()->bothify('AFF-####'),
            'bank' => fake()->randomElement(['LUUM', 'BBVA', 'Banorte', 'Santander']),
            'income' => fake()->randomFloat(2, 100, 10000),
            'order' => 1,
        ];
    }
}
