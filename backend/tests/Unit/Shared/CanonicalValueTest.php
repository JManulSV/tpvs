<?php

namespace Tests\Unit\Shared;

use App\Domain\Shared\History\CanonicalValue;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CanonicalValueTest extends TestCase
{
    public function test_string_trims_and_turns_blank_into_null(): void
    {
        $this->assertSame('Maria Lopez', CanonicalValue::string('  Maria Lopez  '));
        $this->assertSame('7', CanonicalValue::string(7));
        $this->assertNull(CanonicalValue::string('   '));
        $this->assertNull(CanonicalValue::string(null));
    }

    public function test_integer(): void
    {
        $this->assertSame('7', CanonicalValue::integer('7'));
        $this->assertSame('7', CanonicalValue::integer(7));
        $this->assertSame('7', CanonicalValue::integer('007'));
        $this->assertNull(CanonicalValue::integer(''));
        $this->assertNull(CanonicalValue::integer(null));
    }

    public function test_date_accepts_strings_and_carbon_instances(): void
    {
        $this->assertSame('2026-09-14', CanonicalValue::date('2026-09-14'));
        $this->assertSame('2026-09-14', CanonicalValue::date('2026-09-14T10:30:00Z'));
        $this->assertSame('2026-09-14', CanonicalValue::date(CarbonImmutable::parse('2026-09-14 23:59:00')));
        $this->assertNull(CanonicalValue::date(''));
        $this->assertNull(CanonicalValue::date(null));
    }

    public function test_decimal_pads_truncates_and_strips_leading_zeros(): void
    {
        $this->assertSame('1000.00', CanonicalValue::decimal('1000'));
        $this->assertSame('7.50', CanonicalValue::decimal(' 007.5 '));
        $this->assertSame('12.99', CanonicalValue::decimal('12.999'));
        $this->assertSame('0.50', CanonicalValue::decimal('.5'));
        $this->assertSame('-3.10', CanonicalValue::decimal('-3.1'));
        $this->assertSame('12.00', CanonicalValue::decimal(12));
        $this->assertNull(CanonicalValue::decimal(''));
        $this->assertNull(CanonicalValue::decimal(null));
    }
}
