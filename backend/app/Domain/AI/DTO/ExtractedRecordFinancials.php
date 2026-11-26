<?php

namespace App\Domain\AI\DTO;

use App\Domain\Records\RecordFinancials;

/**
 * The provisional money values of an IMAGE record: the incomes and the global
 * percentage that passed the database range checks, and the RecordFinancials
 * calculated from them (null when they cannot be calculated).
 */
final readonly class ExtractedRecordFinancials
{
    /**
     * @param  list<string|null>  $incomes  one per movement, in order
     * @param  list<array{path: string, code: string, severity: string, message: string, value?: mixed}>  $issues
     */
    public function __construct(
        public array $incomes,
        public ?string $commissionPercentage,
        public ?RecordFinancials $financials,
        public array $issues,
    ) {}
}
