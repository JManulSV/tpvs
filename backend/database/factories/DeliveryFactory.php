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
            // Default: all cash, no extra commission (pre-SPEC 28 behavior).
            'requested_cash_amount' => fn (array $attributes) => $attributes['prepared_return_amount'],
            'cash_commission_percentage' => '0.00',
            'cash_commission' => '0.00',
            'cash_amount' => fn (array $attributes) => $attributes['requested_cash_amount'],
        ];
    }
}
