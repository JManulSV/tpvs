<?php

namespace Tests\Unit;

use App\Domain\AI\ExtractionFinancialCalculator;
use Tests\TestCase;

class ExtractionFinancialCalculatorTest extends TestCase
{
    public function test_it_calculates_commission_and_return_from_percentage(): void
    {
        $result = (new ExtractionFinancialCalculator)->calculate($this->detail(
            income: '1234.56',
            percentage: '2.50',
            sourceCommission: '30.86',
            sourceReturn: '1203.70',
        ), 0);

        $this->assertSame('1234.56', $result->income);
        $this->assertSame('2.50', $result->commissionPercentage);
        $this->assertSame('30.86', $result->commission);
        $this->assertSame('1203.70', $result->returnAmount);
        $this->assertSame([], $result->issues);
    }

    public function test_it_uses_valid_source_commission_as_fixed_commission_when_percentage_is_missing(): void
    {
        $result = (new ExtractionFinancialCalculator)->calculate($this->detail(
            income: '1000.00',
            percentage: null,
            sourceCommission: '125.25',
            sourceReturn: '874.75',
        ), 0);

        $this->assertNull($result->commissionPercentage);
        $this->assertSame('125.25', $result->commission);
        $this->assertSame('874.75', $result->returnAmount);
        $this->assertSame([], $result->issues);
    }

    public function test_it_never_uses_source_return_as_official_return_and_reports_discrepancies(): void
    {
        $result = (new ExtractionFinancialCalculator)->calculate($this->detail(
            income: '100.00',
            percentage: '2.00',
            sourceCommission: '1.00',
            sourceReturn: '50.00',
        ), 3);

        $this->assertSame('2.00', $result->commission);
        $this->assertSame('98.00', $result->returnAmount);
        $this->assertSame(
            ['SOURCE_COMMISSION_MISMATCH', 'SOURCE_RETURN_MISMATCH'],
            array_column($result->issues, 'code'),
        );
    }

    public function test_it_leaves_financial_values_null_when_source_values_violate_constraints(): void
    {
        $result = (new ExtractionFinancialCalculator)->calculate($this->detail(
            income: '100.00',
            percentage: null,
            sourceCommission: '101.00',
            sourceReturn: '0.00',
        ), 0);

        $this->assertSame('100.00', $result->income);
        $this->assertNull($result->commission);
        $this->assertNull($result->returnAmount);
        $this->assertContains('FIXED_COMMISSION_EXCEEDS_INCOME', array_column($result->issues, 'code'));
    }

    /** @return array<string, mixed> */
    private function detail(?string $income, ?string $percentage, ?string $sourceCommission, ?string $sourceReturn): array
    {
        return [
            'income' => ['value' => $income, 'confidence' => $income === null ? 0 : 0.9],
            'commission_percentage' => ['value' => $percentage, 'confidence' => $percentage === null ? 0 : 0.9],
            'source_commission' => ['value' => $sourceCommission, 'confidence' => $sourceCommission === null ? 0 : 0.9],
            'source_return_amount' => ['value' => $sourceReturn, 'confidence' => $sourceReturn === null ? 0 : 0.9],
        ];
    }
}
