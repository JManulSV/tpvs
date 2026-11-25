<?php

namespace App\Domain\Records;

use App\Domain\Shared\Money;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BusinessValidator
{
    /** Largest amount records.commission and records.return_amount can hold: decimal(12,2). */
    public const MAX_RECORD_TOTAL_CENTS = 999_999_999_999;

    /**
     * Calculate IMAGE proposal amounts with the same decimal rules used by manual records.
     * Missing source values remain provisional nulls and must be resolved during review.
     *
     * @return array{commission: string|null, return_amount: string|null, issues: list<array{code: string, message: string}>}
     */
    public function calculateProvisionalImageAmounts(?string $income, ?string $percentage, ?string $sourceCommission): array
    {
        if ($income === null) {
            return ['commission' => null, 'return_amount' => null, 'issues' => []];
        }

        $incomeAmount = Money::fromDecimal($income);
        if ($percentage !== null) {
            $commission = $incomeAmount->applyPercentage($percentage);

            if ($commission->compare($incomeAmount) > 0) {
                return [
                    'commission' => null,
                    'return_amount' => null,
                    'issues' => [['code' => 'CALCULATED_COMMISSION_OUT_OF_RANGE', 'message' => 'The calculated commission violates financial limits.']],
                ];
            }

            return [
                'commission' => $commission->toDecimal(),
                'return_amount' => $incomeAmount->subtract($commission)->toDecimal(),
                'issues' => [],
            ];
        }

        if ($sourceCommission === null) {
            return ['commission' => null, 'return_amount' => null, 'issues' => []];
        }

        $commission = Money::fromDecimal($sourceCommission);
        if ($commission->compare($incomeAmount) > 0) {
            return [
                'commission' => null,
                'return_amount' => null,
                'issues' => [['code' => 'FIXED_COMMISSION_EXCEEDS_INCOME', 'message' => 'The source commission exceeds income and cannot be used as a provisional fixed commission.']],
            ];
        }

        return [
            'commission' => $sourceCommission,
            'return_amount' => $incomeAmount->subtract($commission)->toDecimal(),
            'issues' => [],
        ];
    }

    /**
     * Validate the record's business invariants and canonicalize its movements.
     * The global commission percentage is mandatory; the commission and return
     * are derived from it with RecordFinancials.
     *
     * @param  array<string, mixed>  $record
     * @return list<array{affiliation: string, bank: string, income: string, order: int}>
     */
    public function validateManualDetails(array $record): array
    {
        $this->validateBusinessPeriod($record);
        $this->validateCommissionPercentage($record['commission_percentage'] ?? null);

        $details = [];
        $total = Money::zero();
        foreach ($record['details'] as $index => $detail) {
            $income = Money::fromDecimal($detail['income']);
            $total = $total->add($income);
            $details[] = [
                'affiliation' => $detail['affiliation'],
                'bank' => $detail['bank'],
                'income' => $income->toDecimal(),
                'order' => $index + 1,
            ];
        }

        // Each movement fits its own column, but the record's return is stored in one decimal(12,2).
        if ($total->cents > self::MAX_RECORD_TOTAL_CENTS) {
            throw ValidationException::withMessages([
                'details' => ['The total income of the record may not exceed 9999999999.99.'],
            ]);
        }

        return $details;
    }

    /**
     * Revalidate an already persisted record without trusting its stored
     * commission and return: both must match a fresh RecordFinancials calculation.
     */
    public function validatePersistedRecord(Record $record): RecordFinancials
    {
        if ($record->company_id === null) {
            throw ValidationException::withMessages([
                'company_id' => ['A company is required before the record can be confirmed.'],
            ]);
        }

        if ($record->details->isEmpty()) {
            throw ValidationException::withMessages([
                'details' => ['At least one complete movement is required before the record can be confirmed.'],
            ]);
        }

        foreach ($record->details as $detail) {
            foreach (['affiliation', 'bank', 'income'] as $field) {
                if ($detail->{$field} === null || $detail->{$field} === '') {
                    throw ValidationException::withMessages([
                        "details.{$detail->order}.{$field}" => ['This field is required before the record can be confirmed.'],
                    ]);
                }
            }
        }

        $details = $this->validateManualDetails([
            'year' => $record->year,
            'week' => $record->week,
            'week_start_date' => $record->week_start_date?->toDateString(),
            'week_end_date' => $record->week_end_date?->toDateString(),
            'commission_percentage' => $record->commission_percentage,
            'details' => $record->details->map(static fn ($detail): array => [
                'affiliation' => $detail->affiliation,
                'bank' => $detail->bank,
                'income' => $detail->income,
            ])->all(),
        ]);

        foreach ($record->details as $index => $detail) {
            if ((int) $detail->order !== $details[$index]['order']) {
                throw ValidationException::withMessages([
                    "details.{$index}" => ['The persisted movement order is inconsistent.'],
                ]);
            }
        }

        $financials = RecordFinancials::calculate(array_column($details, 'income'), $record->commission_percentage);

        if ($financials === null
            || $record->commission !== $financials->commission
            || $record->return_amount !== $financials->returnAmount) {
            throw ValidationException::withMessages([
                'commission' => ['The persisted commission and return are inconsistent.'],
            ]);
        }

        return $financials;
    }

    private function validateCommissionPercentage(mixed $percentage): void
    {
        if ($percentage === null || $percentage === '') {
            throw ValidationException::withMessages([
                'commission_percentage' => ['This field is required before the record can be confirmed.'],
            ]);
        }

        $hundredths = Money::fromDecimal((string) $percentage)->cents;
        if ($hundredths < 0 || $hundredths > 10_000) {
            throw ValidationException::withMessages([
                'commission_percentage' => ['The commission percentage must be between 0 and 100.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function validateBusinessPeriod(array $record): void
    {
        foreach (['year', 'week', 'week_start_date', 'week_end_date'] as $field) {
            if (! isset($record[$field]) || $record[$field] === '') {
                throw ValidationException::withMessages([
                    $field => ['This field is required before the record can be confirmed.'],
                ]);
            }
        }

        $start = CarbonImmutable::parse($record['week_start_date'])->startOfDay();
        $end = CarbonImmutable::parse($record['week_end_date'])->startOfDay();

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'week_end_date' => ['The period end date must not be earlier than its start date.'],
            ]);
        }
    }
}
