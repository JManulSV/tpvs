<?php

namespace App\Domain\Records;

use App\Domain\Shared\Money;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BusinessValidator
{
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
     * Validate the manual aggregate's business invariants and derive detail money fields.
     *
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    public function validateAndCalculateManualDetails(array $record): array
    {
        $this->validateBusinessPeriod($record);

        $calculated = [];
        foreach ($record['details'] as $index => $detail) {
            $percentageProvided = isset($detail['commission_percentage']);
            $fixedCommissionProvided = isset($detail['commission']);

            if ($percentageProvided === $fixedCommissionProvided) {
                throw ValidationException::withMessages([
                    "details.{$index}.commission" => ['Provide exactly one of commission_percentage or commission.'],
                ]);
            }

            $income = Money::fromDecimal($detail['income']);

            if ($percentageProvided) {
                $commission = $income->applyPercentage($detail['commission_percentage']);
            } else {
                $commission = Money::fromDecimal($detail['commission']);
                if ($commission->compare($income) > 0) {
                    throw ValidationException::withMessages([
                        "details.{$index}.commission" => ['The fixed commission may not exceed income.'],
                    ]);
                }
            }

            if ($commission->compare($income) > 0) {
                throw ValidationException::withMessages([
                    "details.{$index}.commission_percentage" => ['The calculated commission may not exceed income.'],
                ]);
            }

            $calculated[] = [
                'affiliation' => $detail['affiliation'],
                'branch' => $detail['branch'],
                'income' => $income->toDecimal(),
                // Percentages share the two-decimal fixed-point format, so Money canonicalizes them too.
                'commission_percentage' => $percentageProvided ? Money::fromDecimal($detail['commission_percentage'])->toDecimal() : null,
                'commission' => $commission->toDecimal(),
                'return_amount' => $income->subtract($commission)->toDecimal(),
                'order' => $index + 1,
            ];
        }

        return $calculated;
    }

    /**
     * Revalidate an already persisted manual aggregate without trusting its stored calculations.
     *
     * @return list<array<string, mixed>>
     */
    public function validatePersistedManualRecord(Record $record): array
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

        $details = $record->details->map(static function ($detail): array {
            foreach (['affiliation', 'branch', 'income', 'commission', 'return_amount'] as $field) {
                if ($detail->{$field} === null || $detail->{$field} === '') {
                    throw ValidationException::withMessages([
                        "details.{$detail->order}.{$field}" => ['This field is required before the record can be confirmed.'],
                    ]);
                }
            }

            $input = [
                'affiliation' => $detail->affiliation,
                'branch' => $detail->branch,
                'income' => $detail->income,
            ];

            if ($detail->commission_percentage !== null) {
                $input['commission_percentage'] = $detail->commission_percentage;
            } else {
                $input['commission'] = $detail->commission;
            }

            return $input;
        })->all();

        $calculated = $this->validateAndCalculateManualDetails([
            'year' => $record->year,
            'week' => $record->week,
            'week_start_date' => $record->week_start_date?->toDateString(),
            'week_end_date' => $record->week_end_date?->toDateString(),
            'details' => $details,
        ]);

        foreach ($record->details as $index => $detail) {
            if ($detail->commission !== $calculated[$index]['commission']
                || $detail->return_amount !== $calculated[$index]['return_amount']
                || (int) $detail->order !== $calculated[$index]['order']) {
                throw ValidationException::withMessages([
                    "details.{$index}" => ['The persisted movement calculations are inconsistent.'],
                ]);
            }
        }

        return $calculated;
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
