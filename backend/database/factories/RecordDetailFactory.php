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
        $income = fake()->randomFloat(2, 100, 10000);
        $commission = fake()->randomFloat(2, 0, min($income, 500));

        return [
            'record_id' => Record::factory(),
            'operation_number' => fake()->unique()->numerify('OP-#####'),
            'affiliation' => fake()->bothify('AFF-####'),
            'branch' => fake()->city(),
            'income' => $income,
            'commission_percentage' => null,
            'commission' => $commission,
            'return_amount' => round($income - $commission, 2),
            'order' => 1,
        ];
    }
}
