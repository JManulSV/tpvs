<?php

namespace Database\Factories;

use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Record;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Record>
 */
class RecordFactory extends Factory
{
    protected $model = Record::class;

    public function definition(): array
    {
        $year = (int) now()->year;
        $week = fake()->numberBetween(1, 52);
        $weekStart = now()->setISODate($year, $week)->startOfWeek();

        return [
            'company_id' => Company::factory(),
            'source' => RecordSource::MANUAL,
            'status' => RecordStatus::NEEDS_REVIEW,
            'week' => $week,
            'year' => $year,
            'week_start_date' => $weekStart->toDateString(),
            'week_end_date' => $weekStart->copy()->endOfWeek()->toDateString(),
        ];
    }
}
