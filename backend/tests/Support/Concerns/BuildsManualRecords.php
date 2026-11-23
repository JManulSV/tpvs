<?php

namespace Tests\Support\Concerns;

use App\Models\Company;
use App\Models\Record;
use App\Models\RecordDetail;

/** A manual Record with one valid movement, and the API payload that creates one. */
trait BuildsManualRecords
{
    /**
     * @param  array<string, mixed>  $recordAttributes
     * @param  array<string, mixed>  $detailAttributes
     */
    protected function createRecordWithDetails(
        ?Company $company = null,
        array $recordAttributes = [],
        array $detailAttributes = [],
    ): Record {
        $record = Record::factory()
            ->for($company ?? Company::factory())
            ->create($recordAttributes);

        RecordDetail::factory()->for($record)->create(array_merge([
            'income' => '100.00',
            'commission_percentage' => '5.00',
            'commission' => '5.00',
            'return_amount' => '95.00',
            'order' => 1,
            'affiliation' => 'AFF-12345',
            'branch' => 'Guadalajara',
        ], $detailAttributes));

        return $record;
    }

    /**
     * @param  list<array<string, string>>|null  $details
     * @return array<string, mixed>
     */
    protected function validManualRecordPayload(int $companyId, ?array $details = null): array
    {
        $details ??= [[
            'income' => '100.00',
            'commission_percentage' => '5.00',
        ]];

        return [
            'company_id' => $companyId,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
            'details' => array_map(static fn (array $detail, int $index): array => array_merge([
                'affiliation' => 'AFF-'.($index + 123),
                'branch' => 'Guadalajara',
            ], $detail), $details, array_keys($details)),
        ];
    }
}
