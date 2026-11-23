<?php

namespace App\Domain\Records;

final readonly class RecordFilters
{
    public function __construct(
        public ?int $companyId = null,
        public ?int $year = null,
        public ?int $month = null,
        public ?int $week = null,
        public ?string $status = null,
        public ?string $source = null,
        public ?string $search = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $search = $validated['search'] ?? null;

        return new self(
            isset($validated['company_id']) ? (int) $validated['company_id'] : null,
            isset($validated['year']) ? (int) $validated['year'] : null,
            isset($validated['month']) ? (int) $validated['month'] : null,
            isset($validated['week']) ? (int) $validated['week'] : null,
            $validated['status'] ?? null,
            $validated['source'] ?? null,
            $search === null || $search === '' ? null : (string) $search,
        );
    }
}
