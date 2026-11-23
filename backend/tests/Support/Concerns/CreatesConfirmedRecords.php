<?php

namespace Tests\Support\Concerns;

use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Record;
use App\Models\RecordDetail;

trait CreatesConfirmedRecords
{
    /**
     * A CONFIRMED Record for week 38 of 2026 unless $attributes say otherwise.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: string}>  $movements  [income, commission] pairs
     */
    protected function record(Company $company, array $attributes, array $movements): Record
    {
        $record = Record::factory()->create(array_merge([
            'company_id' => $company->getKey(),
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-07',
            'week_end_date' => '2026-09-13',
        ], $attributes));

        foreach ($movements as $index => [$income, $commission]) {
            RecordDetail::factory()->create([
                'record_id' => $record->getKey(),
                'income' => $income,
                'commission' => $commission,
                'return_amount' => number_format((float) $income - (float) $commission, 2, '.', ''),
                'order' => $index + 1,
            ]);
        }

        return $record;
    }
}
