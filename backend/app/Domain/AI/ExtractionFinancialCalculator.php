<?php

namespace App\Domain\AI;

use App\Domain\AI\DTO\ExtractedRecordFinancials;
use App\Domain\AI\DTO\ExtractionIssue;
use App\Domain\Records\BusinessValidator;
use App\Domain\Records\RecordFinancials;
use App\Domain\Shared\Money;

/**
 * Guard the normalized extraction against the database ranges and calculate the
 * provisional commission and return once over the total income with
 * RecordFinancials (SPEC 27). The printed commission is never used as a fallback.
 */
class ExtractionFinancialCalculator
{
    private const MAX_MONEY_CENTS = 999_999_999_999;

    private const MAX_PERCENTAGE_HUNDREDTHS = 10_000;

    /**
     * @param  array<string, mixed>  $extraction  the normalized v6 extraction
     */
    public function calculate(array $extraction): ExtractedRecordFinancials
    {
        $issues = [];

        $incomes = [];
        foreach ($extraction['details'] as $index => $detail) {
            $incomes[] = $this->money($this->value($detail, 'income'), "details.{$index}.income", $issues);
        }

        $percentage = $this->percentage(
            $this->value($extraction['record'], 'commission_percentage'),
            'record.commission_percentage',
            $issues,
        );

        $financials = RecordFinancials::calculate($incomes, $percentage);

        // Each movement fits its own column, but the record's return is stored in one decimal(12,2).
        if ($financials !== null && Money::fromDecimal($financials->incomeTotal)->cents > BusinessValidator::MAX_RECORD_TOTAL_CENTS) {
            $issues[] = ExtractionIssue::review('details', 'RECORD_TOTAL_OUT_OF_RANGE', 'The total income of the record exceeds the supported database range.')
                ->withValue($financials->incomeTotal)
                ->toArray();
            $financials = null;
        }

        return new ExtractedRecordFinancials($incomes, $percentage, $financials, $issues);
    }

    private function money(mixed $value, string $path, array &$issues): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^(?:0|[1-9]\d{0,9})\.\d{2}$/D', $value) !== 1 || Money::fromDecimal($value)->cents > self::MAX_MONEY_CENTS) {
            $issues[] = ExtractionIssue::review($path, 'MONEY_OUT_OF_RANGE', 'The monetary value is outside the supported database range.')->withValue($value)->toArray();

            return null;
        }

        return $value;
    }

    private function percentage(mixed $value, string $path, array &$issues): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^(?:0|[1-9]\d{0,2})\.\d{2}$/D', $value) !== 1 || Money::fromDecimal($value)->cents > self::MAX_PERCENTAGE_HUNDREDTHS) {
            $issues[] = ExtractionIssue::review($path, 'PERCENTAGE_OUT_OF_RANGE', 'The commission percentage must be between 0 and 100.')->withValue($value)->toArray();

            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $fields */
    private function value(array $fields, string $field): mixed
    {
        $fieldValue = $fields[$field] ?? null;

        return is_array($fieldValue) ? ($fieldValue['value'] ?? null) : null;
    }
}
