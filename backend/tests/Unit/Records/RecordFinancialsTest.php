<?php

namespace Tests\Unit\Records;

use App\Domain\Records\RecordFinancials;
use PHPUnit\Framework\TestCase;

class RecordFinancialsTest extends TestCase
{
    public function test_it_matches_the_reference_image(): void
    {
        // test-images/nuevo-formato.png: 236741.30 × 6.5% = 15388.1845 → 15388.18
        $financials = RecordFinancials::calculate(['236741.30'], '6.5');

        $this->assertNotNull($financials);
        $this->assertSame('236741.30', $financials->incomeTotal);
        $this->assertSame('6.50', $financials->commissionPercentage);
        $this->assertSame('15388.18', $financials->commission);
        $this->assertSame('221353.12', $financials->returnAmount);
    }

    public function test_it_calculates_the_commission_once_over_the_total_income(): void
    {
        // Per movement: 100.10 × 6.5% = 6.5065 → 6.51, three times = 19.53.
        // Over the total: 300.30 × 6.5% = 19.5195 → 19.52.
        $financials = RecordFinancials::calculate(['100.10', '100.10', '100.10'], '6.50');

        $this->assertNotNull($financials);
        $this->assertSame('300.30', $financials->incomeTotal);
        $this->assertSame('19.52', $financials->commission);
        $this->assertSame('280.78', $financials->returnAmount);
    }

    public function test_it_returns_null_without_a_percentage(): void
    {
        $this->assertNull(RecordFinancials::calculate(['1000.00'], null));
        $this->assertNull(RecordFinancials::calculate(['1000.00'], ''));
    }

    public function test_it_returns_null_when_any_income_is_missing(): void
    {
        $this->assertNull(RecordFinancials::calculate(['1000.00', null], '6.50'));
        $this->assertNull(RecordFinancials::calculate(['', '1000.00'], '6.50'));
    }

    public function test_it_returns_null_without_movements(): void
    {
        $this->assertNull(RecordFinancials::calculate([], '6.50'));
    }

    public function test_a_zero_percentage_returns_the_full_income(): void
    {
        $financials = RecordFinancials::calculate(['1234.56'], '0');

        $this->assertNotNull($financials);
        $this->assertSame('0.00', $financials->commissionPercentage);
        $this->assertSame('0.00', $financials->commission);
        $this->assertSame('1234.56', $financials->returnAmount);
    }

    public function test_a_full_percentage_leaves_no_return(): void
    {
        $financials = RecordFinancials::calculate(['1234.56'], '100.00');

        $this->assertNotNull($financials);
        $this->assertSame('1234.56', $financials->commission);
        $this->assertSame('0.00', $financials->returnAmount);
    }

    public function test_it_rounds_half_a_cent_up(): void
    {
        // 1.00 × 0.5% = 0.005 → 0.01; 1.00 × 0.49% = 0.0049 → 0.00
        $this->assertSame('0.01', RecordFinancials::calculate(['1.00'], '0.50')?->commission);
        $this->assertSame('0.99', RecordFinancials::calculate(['1.00'], '0.50')?->returnAmount);
        $this->assertSame('0.00', RecordFinancials::calculate(['1.00'], '0.49')?->commission);
    }

    public function test_attributes_maps_to_record_columns(): void
    {
        $this->assertSame(
            ['commission' => '15388.18', 'return_amount' => '221353.12'],
            RecordFinancials::attributes(RecordFinancials::calculate(['236741.30'], '6.50')),
        );
        $this->assertSame(
            ['commission' => null, 'return_amount' => null],
            RecordFinancials::attributes(null),
        );
    }
}
