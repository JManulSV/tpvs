<?php

namespace App\Domain\Records;

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

        $incomeCents = $this->toCents($income);
        if ($percentage !== null) {
            $percentageHundredths = $this->toHundredths($percentage);
            $commissionCents = intdiv(($incomeCents * $percentageHundredths) + 5000, 10000);

            if ($commissionCents > $incomeCents) {
                return [
                    'commission' => null,
                    'return_amount' => null,
                    'issues' => [['code' => 'CALCULATED_COMMISSION_OUT_OF_RANGE', 'message' => 'The calculated commission violates financial limits.']],
                ];
            }

            return [
                'commission' => $this->fromCents($commissionCents),
                'return_amount' => $this->fromCents($incomeCents - $commissionCents),
                'issues' => [],
            ];
        }

        if ($sourceCommission === null) {
            return ['commission' => null, 'return_amount' => null, 'issues' => []];
        }

        $commissionCents = $this->toCents($sourceCommission);
        if ($commissionCents > $incomeCents) {
            return [
                'commission' => null,
                'return_amount' => null,
                'issues' => [['code' => 'FIXED_COMMISSION_EXCEEDS_INCOME', 'message' => 'The source commission exceeds income and cannot be used as a provisional fixed commission.']],
            ];
        }

        return [
            'commission' => $sourceCommission,
            'return_amount' => $this->fromCents($incomeCents - $commissionCents),
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

            $incomeCents = $this->toCents($detail['income']);

            if ($percentageProvided) {
                $percentageHundredths = $this->toHundredths($detail['commission_percentage']);
                $commissionCents = intdiv(($incomeCents * $percentageHundredths) + 5000, 10000);
            } else {
                $commissionCents = $this->toCents($detail['commission']);
                if ($commissionCents > $incomeCents) {
                    throw ValidationException::withMessages([
                        "details.{$index}.commission" => ['The fixed commission may not exceed income.'],
                    ]);
                }
            }

            if ($commissionCents > $incomeCents) {
                throw ValidationException::withMessages([
                    "details.{$index}.commission_percentage" => ['The calculated commission may not exceed income.'],
                ]);
            }

            $calculated[] = [
                'affiliation' => $detail['affiliation'],
                'branch' => $detail['branch'],
                'income' => $this->fromCents($incomeCents),
                'commission_percentage' => $percentageProvided ? $this->fromHundredths($percentageHundredths) : null,
                'commission' => $this->fromCents($commissionCents),
                'return_amount' => $this->fromCents($incomeCents - $commissionCents),
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

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function toHundredths(string $percentage): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percentage, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    private function fromHundredths(int $percentage): string
    {
        return intdiv($percentage, 100).'.'.str_pad((string) ($percentage % 100), 2, '0', STR_PAD_LEFT);
    }
}
