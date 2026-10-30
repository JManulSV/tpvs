<?php

namespace App\Domain\Reports;

final readonly class ReportFilters
{
    public function __construct(
        public int $year,
        public ?int $month = null,
        public ?int $week = null,
        public ?int $companyId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            (int) $validated['year'],
            isset($validated['month']) ? (int) $validated['month'] : null,
            isset($validated['week']) ? (int) $validated['week'] : null,
            isset($validated['company_id']) ? (int) $validated['company_id'] : null,
        );
    }

    /**
     * @return array{year: int, month: int|null, week: int|null, company_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'week' => $this->week,
            'company_id' => $this->companyId,
        ];
    }
}
