<?php

namespace Tests\Unit;

use App\Domain\AI\ExtractionFinancialCalculator;
use Tests\TestCase;

class ExtractionFinancialCalculatorTest extends TestCase
{
    public function test_it_calculates_the_global_commission_once_over_the_total_income(): void
    {
        // test-images/nuevo-formato.png: 236741.30 × 6.5% = 15388.18; return 221353.12.
        $result = (new ExtractionFinancialCalculator)->calculate($this->extraction(['236741.30'], '6.50'));

        $this->assertSame(['236741.30'], $result->incomes);
        $this->assertSame('6.50', $result->commissionPercentage);
        $this->assertSame('15388.18', $result->financials?->commission);
        $this->assertSame('221353.12', $result->financials?->returnAmount);
        $this->assertSame([], $result->issues);
    }

    public function test_several_movements_are_not_rounded_per_row(): void
    {
        // Per row: 3 × 6.51 = 19.53. Once over 300.30: 19.52.
        $result = (new ExtractionFinancialCalculator)->calculate($this->extraction(['100.10', '100.10', '100.10'], '6.50'));

        $this->assertSame('19.52', $result->financials?->commission);
        $this->assertSame('280.78', $result->financials?->returnAmount);
    }

    public function test_without_a_percentage_nothing_is_calculated_and_there_is_no_fallback(): void
    {
        $result = (new ExtractionFinancialCalculator)->calculate($this->extraction(['1000.00'], null));

        $this->assertSame(['1000.00'], $result->incomes);
        $this->assertNull($result->commissionPercentage);
        $this->assertNull($result->financials);
    }

    public function test_a_missing_income_leaves_the_totals_uncalculated(): void
    {
        $result = (new ExtractionFinancialCalculator)->calculate($this->extraction(['1000.00', null], '6.50'));

        $this->assertSame(['1000.00', null], $result->incomes);
        $this->assertSame('6.50', $result->commissionPercentage);
        $this->assertNull($result->financials);
    }

    public function test_values_outside_the_database_ranges_become_null_with_issues(): void
    {
        $result = (new ExtractionFinancialCalculator)->calculate($this->extraction(['10000000000.00'], '101.00'));

        $this->assertSame([null], $result->incomes);
        $this->assertNull($result->commissionPercentage);
        $this->assertNull($result->financials);
        $this->assertSame(
            [['details.0.income', 'MONEY_OUT_OF_RANGE'], ['record.commission_percentage', 'PERCENTAGE_OUT_OF_RANGE']],
            array_map(static fn (array $issue): array => [$issue['path'], $issue['code']], $result->issues),
        );
    }

    public function test_a_total_income_that_does_not_fit_the_record_is_not_calculated(): void
    {
        $result = (new ExtractionFinancialCalculator)->calculate($this->extraction(['9999999999.99', '0.01'], '0.00'));

        $this->assertSame(['9999999999.99', '0.01'], $result->incomes);
        $this->assertNull($result->financials);
        $this->assertSame(['RECORD_TOTAL_OUT_OF_RANGE'], array_column($result->issues, 'code'));
    }

    /**
     * @param  list<string|null>  $incomes
     * @return array<string, mixed>
     */
    private function extraction(array $incomes, ?string $percentage): array
    {
        return [
            'record' => [
                'commission_percentage' => ['value' => $percentage, 'confidence' => $percentage === null ? 0 : 0.9],
            ],
            'details' => array_map(static fn (?string $income): array => [
                'income' => ['value' => $income, 'confidence' => $income === null ? 0 : 0.9],
            ], $incomes),
        ];
    }
}
