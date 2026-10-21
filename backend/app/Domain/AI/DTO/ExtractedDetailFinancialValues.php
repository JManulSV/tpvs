<?php

namespace App\Domain\AI\DTO;

final readonly class ExtractedDetailFinancialValues
{
    /**
     * @param  list<array{path: string, code: string, severity: string, message: string, value?: mixed}>  $issues
     */
    public function __construct(
        public ?string $income,
        public ?string $commissionPercentage,
        public ?string $commission,
        public ?string $returnAmount,
        public array $issues,
    ) {}
}
