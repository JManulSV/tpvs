<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\DeliveryCashDenomination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryCashDenomination>
 */
class DeliveryCashDenominationFactory extends Factory
{
    protected $model = DeliveryCashDenomination::class;

    public function definition(): array
    {
        return [
            'delivery_id' => Delivery::factory(),
            'value' => 100.00,
            'quantity' => 1,
        ];
    }
}
