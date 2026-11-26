<?php

namespace Tests\Unit\Deliveries;

use App\Domain\Deliveries\DeliverySplit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeliverySplitTest extends TestCase
{
    /**
     * The table of SPEC 28 plus 100% and a half-cent commission.
     *
     * @return array<string, array{string, string, string, string, string, string}>
     */
    public static function cases(): array
    {
        return [
            'mixed split' => ['200.00', '100.00', '10.00', '100.00', '10.00', '90.00'],
            'all cash' => ['200.00', '200.00', '10.00', '0.00', '20.00', '180.00'],
            'all transfer' => ['200.00', '0.00', '10.00', '200.00', '0.00', '0.00'],
            'no extra commission' => ['200.00', '100.00', '0.00', '100.00', '0.00', '100.00'],
            // 100.05 × 6.5% = 6.50325 → 6.50
            'rounds down below half a cent' => ['100.05', '100.05', '6.50', '0.00', '6.50', '93.55'],
            'one hundred percent' => ['200.00', '150.00', '100.00', '50.00', '150.00', '0.00'],
            // 0.10 × 5% = 0.005 → 0.01 (half-up)
            'half a cent rounds up' => ['0.10', '0.10', '5.00', '0.00', '0.01', '0.09'],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_splits_the_official_return(
        string $return,
        string $requestedCash,
        string $percentage,
        string $transfer,
        string $commission,
        string $cash,
    ): void {
        $split = DeliverySplit::calculate($return, $requestedCash, $percentage);

        $this->assertSame($transfer, $split->transferAmount);
        $this->assertSame($commission, $split->cashCommission);
        $this->assertSame($cash, $split->cashAmount);
    }

    public function test_it_canonicalizes_inputs_and_exposes_the_persisted_columns(): void
    {
        $split = DeliverySplit::calculate('200', '100.5', '10');

        $this->assertSame('200.00', $split->returnAmount);
        $this->assertSame([
            'requested_cash_amount' => '100.50',
            'cash_commission_percentage' => '10.00',
            'cash_commission' => '10.05',
            'cash_amount' => '90.45',
        ], $split->attributes());
    }

    public function test_it_reports_whether_there_is_cash_to_deliver(): void
    {
        $this->assertTrue(DeliverySplit::calculate('200.00', '100.00', '10.00')->hasCash());
        $this->assertFalse(DeliverySplit::calculate('200.00', '0.00', '10.00')->hasCash());
        $this->assertFalse(DeliverySplit::calculate('200.00', '150.00', '100.00')->hasCash());
    }
}
