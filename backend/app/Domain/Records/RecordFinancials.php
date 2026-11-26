<?php

namespace App\Domain\Records;

use App\Domain\Shared\Money;

/**
 * The official commission and return of a Record (SPEC 27).
 *
 * The commission is calculated once over the total income of all movements
 * with Money::applyPercentage (half-up to the cent), never per movement, so
 * it matches the total printed on the document. Return = income − commission.
 *
 * The percentage is expected to be already validated (0–100, two decimals).
 */
final readonly class RecordFinancials
{
    private function __construct(
        public string $incomeTotal,
        public string $commissionPercentage,
        public string $commission,
        public string $returnAmount,
    ) {}

    /**
     * Returns null when the values cannot be calculated: no percentage, no
     * movements, or any movement without income.
     *
     * @param  list<string|null>  $incomes  decimal strings, one per movement
     */
    public static function calculate(array $incomes, ?string $percentage): ?self
    {
        if ($percentage === null || $percentage === '' || $incomes === []) {
            return null;
        }

        $total = Money::zero();
        foreach ($incomes as $income) {
            if ($income === null || $income === '') {
                return null;
            }

            $total = $total->add(Money::fromDecimal($income));
        }

        $commission = $total->applyPercentage($percentage);

        return new self(
            $total->toDecimal(),
            // Percentages share the two-decimal fixed-point format, so Money canonicalizes them too.
            Money::fromDecimal($percentage)->toDecimal(),
            $commission->toDecimal(),
            $total->subtract($commission)->toDecimal(),
        );
    }

    /**
     * The derived Record columns. Both are null when nothing can be calculated.
     *
     * @return array{commission: string|null, return_amount: string|null}
     */
    public static function attributes(?self $financials): array
    {
        return [
            'commission' => $financials?->commission,
            'return_amount' => $financials?->returnAmount,
        ];
    }
}
