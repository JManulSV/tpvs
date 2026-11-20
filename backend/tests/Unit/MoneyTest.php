<?php

namespace Tests\Unit;

use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_parses_decimals_into_cents(): void
    {
        $this->assertSame(123450, Money::fromDecimal('1234.50')->cents);
        $this->assertSame(123450, Money::fromDecimal('1234.5')->cents);
        $this->assertSame(100000, Money::fromDecimal('1000')->cents);
        $this->assertSame(700, Money::fromDecimal(7)->cents);
        $this->assertSame(5, Money::fromDecimal('0.05')->cents);
    }

    public function test_it_truncates_beyond_two_decimals_without_rounding(): void
    {
        $this->assertSame(1299, Money::fromDecimal('12.999')->cents);
        $this->assertSame(1200, Money::fromDecimal('12.001')->cents);
        $this->assertSame(-1299, Money::fromDecimal('-12.999')->cents);
    }

    public function test_it_handles_negative_amounts(): void
    {
        $this->assertSame(-50, Money::fromDecimal('-0.50')->cents);
        $this->assertSame(-123405, Money::fromDecimal('-1234.05')->cents);
        $this->assertSame('-0.50', Money::fromCents(-50)->toDecimal());
        $this->assertSame('-1234.05', Money::fromCents(-123405)->toDecimal());
        $this->assertTrue(Money::fromDecimal('-0.01')->isNegative());
        $this->assertFalse(Money::zero()->isNegative());
    }

    public function test_null_and_empty_string_are_zero(): void
    {
        $this->assertSame(0, Money::fromDecimal(null)->cents);
        $this->assertSame(0, Money::fromDecimal('')->cents);
        $this->assertSame('0.00', Money::fromDecimal(null)->toDecimal());
    }

    public function test_it_formats_as_a_two_decimal_string(): void
    {
        $this->assertSame('1234.50', Money::fromCents(123450)->toDecimal());
        $this->assertSame('0.05', Money::fromCents(5)->toDecimal());
        $this->assertSame('0.00', Money::zero()->toDecimal());
    }

    public function test_arithmetic_and_comparison(): void
    {
        $a = Money::fromDecimal('10.25');
        $b = Money::fromDecimal('0.75');

        $this->assertSame('11.00', $a->add($b)->toDecimal());
        $this->assertSame('9.50', $a->subtract($b)->toDecimal());
        $this->assertSame('-9.50', $b->subtract($a)->toDecimal());
        $this->assertSame('30.75', $a->multiply(3)->toDecimal());
        $this->assertSame(1, $a->compare($b));
        $this->assertSame(-1, $b->compare($a));
        $this->assertSame(0, $a->compare(Money::fromCents(1025)));
        $this->assertSame('10.25', $a->toDecimal(), 'operations do not mutate');
    }

    public function test_apply_percentage_rounds_half_up_to_the_cent(): void
    {
        $this->assertSame('125.00', Money::fromDecimal('1000.00')->applyPercentage('12.5')->toDecimal());
        $this->assertSame('0.01', Money::fromDecimal('0.10')->applyPercentage('5.00')->toDecimal(), '0.005 rounds up');
        $this->assertSame('0.00', Money::fromDecimal('0.10')->applyPercentage('4.99')->toDecimal(), '0.00499 rounds down');
        $this->assertSame('12.35', Money::fromDecimal('123.45')->applyPercentage('10')->toDecimal(), '12.345 rounds up');
        $this->assertSame('100.00', Money::fromDecimal('100.00')->applyPercentage('100.00')->toDecimal());
        $this->assertSame('0.00', Money::fromDecimal('100.00')->applyPercentage('0')->toDecimal());
    }

    public function test_floor_to_whole_peso(): void
    {
        $this->assertSame('12.00', Money::fromDecimal('12.75')->floorToWholePeso()->toDecimal());
        $this->assertSame('12.00', Money::fromDecimal('12.00')->floorToWholePeso()->toDecimal());
        $this->assertSame('0.00', Money::fromDecimal('0.99')->floorToWholePeso()->toDecimal());
        $this->assertSame('-1.00', Money::fromDecimal('-0.50')->floorToWholePeso()->toDecimal());
        $this->assertSame('-2.00', Money::fromDecimal('-2.00')->floorToWholePeso()->toDecimal());
    }
}
