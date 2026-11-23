<?php

namespace Tests\Unit\Deliveries;

use App\Domain\Deliveries\DeliveryInputNormalizer;
use InvalidArgumentException;
use Tests\TestCase;

class DeliveryInputNormalizerTest extends TestCase
{
    public function test_it_drops_zero_quantities_and_sorts_denominations_descending(): void
    {
        $canonical = $this->normalizer()->canonicalizeDenominations([
            ['value' => '500.00', 'quantity' => 1],
            ['value' => '0.50', 'quantity' => 0],
            ['value' => '1000', 'quantity' => 2],
            ['value' => '20.0', 'quantity' => 3],
        ]);

        $this->assertSame([
            ['value' => '1000.00', 'quantity' => 2],
            ['value' => '500.00', 'quantity' => 1],
            ['value' => '20.00', 'quantity' => 3],
        ], $canonical);
    }

    public function test_it_canonicalizes_delivery_history_values(): void
    {
        $normalizer = $this->normalizer();

        $this->assertSame('2026-09-18', $normalizer->canonicalDate('2026-09-18'));
        $this->assertSame('Maria Lopez', $normalizer->canonicalName('  Maria Lopez  '));
        $this->assertNull($normalizer->canonicalName('   '));
        $this->assertSame('100.50', $normalizer->canonicalAmount('00100.5'));
        $this->assertSame('-0.50', $normalizer->canonicalAmount('-0.5'));
        $this->assertSame('0.00', $normalizer->canonicalAmount('-0.00'));
        $this->assertSame('5', $normalizer->canonicalQuantity('5'));
    }

    public function test_it_rejects_unsupported_denominations_when_canonicalizing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer()->canonicalizeDenominations([
            ['value' => '25.00', 'quantity' => 1],
        ]);
    }

    private function normalizer(): DeliveryInputNormalizer
    {
        return new DeliveryInputNormalizer;
    }
}
