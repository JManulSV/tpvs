<?php

namespace App\Domain\AI;

use App\Domain\AI\DTO\ExtractedDetailFinancialValues;
use App\Domain\Records\BusinessValidator;

class ExtractionFinancialCalculator
{
    private const MAX_MONEY_CENTS = 999_999_999_999;

    public function __construct(private readonly BusinessValidator $businessValidator = new BusinessValidator) {}

    /**
     * Calculate official provisional financial values from normalized evidence.
     * Source returns are never accepted as authoritative values.
     *
     * @param  array<string, mixed>  $detail
     */
    public function calculate(array $detail, int $index): ExtractedDetailFinancialValues
    {
        $issues = [];
        $income = $this->money($this->value($detail, 'income'), "details.{$index}.income", $issues);
        $percentage = $this->percentage($this->value($detail, 'commission_percentage'), "details.{$index}.commission_percentage", $issues);
        $sourceCommission = $this->money($this->value($detail, 'source_commission'), "details.{$index}.source_commission", $issues);
        $sourceReturn = $this->money($this->value($detail, 'source_return_amount'), "details.{$index}.source_return_amount", $issues);

        $calculated = $this->businessValidator->calculateProvisionalImageAmounts($income, $percentage, $sourceCommission);
        $commission = $calculated['commission'];
        $returnAmount = $calculated['return_amount'];
        foreach ($calculated['issues'] as $calculationIssue) {
            $issues[] = $this->issue("details.{$index}.commission", $calculationIssue['code'], $calculationIssue['message']);
        }

        if ($commission !== null && $sourceCommission !== null && $commission !== $sourceCommission) {
            $issues[] = $this->issue("details.{$index}.source_commission", 'SOURCE_COMMISSION_MISMATCH', 'The printed commission differs from the backend-calculated commission.', $sourceCommission);
        }

        if ($returnAmount !== null && $sourceReturn !== null && $returnAmount !== $sourceReturn) {
            $issues[] = $this->issue("details.{$index}.source_return_amount", 'SOURCE_RETURN_MISMATCH', 'The printed return differs from the backend-calculated return.', $sourceReturn);
        }

        return new ExtractedDetailFinancialValues($income, $percentage, $commission, $returnAmount, $issues);
    }

    private function money(mixed $value, string $path, array &$issues): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^(?:0|[1-9]\d{0,9})\.\d{2}$/D', $value) !== 1 || $this->toCents($value) > self::MAX_MONEY_CENTS) {
            $issues[] = $this->issue($path, 'MONEY_OUT_OF_RANGE', 'The monetary value is outside the supported database range.', $value);

            return null;
        }

        return $value;
    }

    private function percentage(mixed $value, string $path, array &$issues): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^(?:0|[1-9]\d{0,2})\.\d{2}$/D', $value) !== 1 || $this->toCents($value) > 10000) {
            $issues[] = $this->issue($path, 'PERCENTAGE_OUT_OF_RANGE', 'The commission percentage must be between 0 and 100.', $value);

            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $detail */
    private function value(array $detail, string $field): mixed
    {
        $fieldValue = $detail[$field] ?? null;

        return is_array($fieldValue) ? ($fieldValue['value'] ?? null) : null;
    }

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = explode('.', $amount, 2);

        return ((int) $whole * 100) + (int) $fraction;
    }

    /** @return array{path: string, code: string, severity: string, message: string, value?: mixed} */
    private function issue(string $path, string $code, string $message, mixed $value = null): array
    {
        $issue = ['path' => $path, 'code' => $code, 'severity' => 'review', 'message' => $message];
        if (func_num_args() >= 4) {
            $issue['value'] = $value;
        }

        return $issue;
    }
}
