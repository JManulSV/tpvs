<?php

namespace Tests\Support\Concerns;

use App\Domain\Records\RecordFinancials;
use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Record;
use App\Models\RecordDetail;

trait CreatesConfirmedRecords
{
    /**
     * A CONFIRMED Record for week 38 of 2026 at a 10.00% global commission
     * unless $attributes say otherwise. Commission and return are calculated
     * once over the total income with RecordFinancials, as Laravel stores them.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $incomes  one income per movement
     */
    protected function record(Company $company, array $attributes, array $incomes): Record
    {
        $percentage = $attributes['commission_percentage'] ?? '10.00';

        $record = Record::factory()->create(array_merge([
            'company_id' => $company->getKey(),
            'status' => RecordStatus::CONFIRMED,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-07',
            'week_end_date' => '2026-09-13',
            'commission_percentage' => $percentage,
            ...RecordFinancials::attributes(RecordFinancials::calculate($incomes, $percentage)),
        ], $attributes));

        foreach ($incomes as $index => $income) {
            RecordDetail::factory()->create([
                'record_id' => $record->getKey(),
                'income' => $income,
                'order' => $index + 1,
            ]);
        }

        return $record;
    }
}
