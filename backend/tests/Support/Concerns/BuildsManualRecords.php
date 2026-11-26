<?php

namespace Tests\Support\Concerns;

use App\Models\Company;
use App\Models\Record;
use App\Models\RecordDetail;

/** A manual Record with one valid movement, and the API payload that creates one. */
trait BuildsManualRecords
{
    /**
     * A Record with one 100.00 movement at a 5.00% global commission
     * (commission 5.00, return 95.00) unless the attributes say otherwise.
     *
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
            ->create(array_merge([
                'commission_percentage' => '5.00',
                'commission' => '5.00',
                'return_amount' => '95.00',
            ], $recordAttributes));

        RecordDetail::factory()->for($record)->create(array_merge([
            'income' => '100.00',
            'order' => 1,
            'affiliation' => 'AFF-12345',
            'bank' => 'LUUM',
        ], $detailAttributes));

        return $record;
    }

    /**
     * @param  list<array<string, string>>|null  $details
     * @return array<string, mixed>
     */
    protected function validManualRecordPayload(
        int $companyId,
        ?array $details = null,
        string $commissionPercentage = '5.00',
    ): array {
        $details ??= [['income' => '100.00']];

        return [
            'company_id' => $companyId,
            'year' => 2026,
            'week' => 38,
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
            'commission_percentage' => $commissionPercentage,
            'details' => array_map(static fn (array $detail, int $index): array => array_merge([
                'affiliation' => 'AFF-'.($index + 123),
                'bank' => 'LUUM',
            ], $detail), $details, array_keys($details)),
        ];
    }
}
