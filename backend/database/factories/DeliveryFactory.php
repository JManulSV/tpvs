<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\Record;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    public function definition(): array
    {
        return [
            'record_id' => Record::factory(),
            'delivery_date' => now()->toDateString(),
            'delivered_by_name' => fake()->name(),
            'received_by_name' => fake()->name(),
            'prepared_return_amount' => fake()->randomFloat(2, 0, 10000),
        ];
    }
}
