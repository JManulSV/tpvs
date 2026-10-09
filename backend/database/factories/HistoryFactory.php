<?php

namespace Database\Factories;

use App\Models\History;
use App\Models\Record;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<History>
 */
class HistoryFactory extends Factory
{
    protected $model = History::class;

    public function definition(): array
    {
        return [
            'record_id' => Record::factory(),
            'record_detail_id' => null,
            'delivery_id' => null,
            'user_id' => User::factory(),
            'field_name' => 'status',
            'previous_value' => 'PENDING',
            'new_value' => 'NEEDS_REVIEW',
            'reason' => 'Factory test change',
        ];
    }
}
