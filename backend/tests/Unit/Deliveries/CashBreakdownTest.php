<?php

namespace Tests\Unit\Deliveries;

use App\Domain\Deliveries\CashBreakdown;
use App\Domain\Documents\Exceptions\DocumentGenerationBlockedException;
use Tests\TestCase;

class CashBreakdownTest extends TestCase
{
    public function test_it_recognizes_only_the_official_denominations(): void
    {
        $breakdown = $this->breakdown();

        foreach (['1000', '1000.0', '1000.00', '0.5', '0.50', '1'] as $value) {
            $this->assertTrue($breakdown->isOfficialDenomination($value), $value);
        }

        foreach (['25.00', '0.25', '-1000.00', '1000.000', 'abc'] as $value) {
            $this->assertFalse($breakdown->isOfficialDenomination($value), $value);
        }
    }

    public function test_it_calculates_the_cash_total(): void
    {
        $breakdown = $this->breakdown();

        $this->assertSame('3.50', $breakdown->cashTotal([
            ['value' => '0.50', 'quantity' => 3],
            ['value' => '1', 'quantity' => 2],
        ]));
    }

    public function test_it_detects_a_cash_mismatch_with_expected_cash_and_difference(): void
    {
        $breakdown = $this->breakdown();

        $this->assertNull($breakdown->cashMismatch('100.00', [
            ['value' => '20.00', 'quantity' => 5],
        ]));

        $this->assertSame([
            'expected_amount' => '100.00',
            'cash_amount' => '80.50',
            'difference' => '-19.50',
        ], $breakdown->cashMismatch('100.00', [
            ['value' => '20.00', 'quantity' => 4],
            ['value' => '0.50', 'quantity' => 1],
        ]));
    }

    public function test_document_cash_reconciliation_accepts_exact_one_peso_differences_without_change(): void
    {
        $breakdown = $this->breakdown();

        $cases = [
            [
                [['value' => '100.00', 'quantity' => 1], ['value' => '10.00', 'quantity' => 1], ['value' => '5.00', 'quantity' => 1], ['value' => '2.00', 'quantity' => 2]],
                '119.00',
                '-1.00',
            ],
            [
                [['value' => '100.00', 'quantity' => 1], ['value' => '20.00', 'quantity' => 1]],
                '120.00',
                '0.00',
            ],
            [
                [['value' => '100.00', 'quantity' => 1], ['value' => '20.00', 'quantity' => 1], ['value' => '1.00', 'quantity' => 1]],
                '121.00',
                '1.00',
            ],
        ];

        foreach ($cases as [$denominations, $cash, $difference]) {
            $reconciliation = $breakdown->documentCashReconciliation('120.00', $denominations, false);

            $this->assertSame('120.00', $reconciliation['expected_amount']);
            $this->assertSame($cash, $reconciliation['cash_amount']);
            $this->assertSame($difference, $reconciliation['difference']);
            $this->assertNull($reconciliation['change_amount']);
            $this->assertNull($reconciliation['reason']);
        }
    }

    public function test_document_cash_reconciliation_blocks_shortfalls_over_one_peso(): void
    {
        $reconciliation = $this->breakdown()->documentCashReconciliation('120.00', [
            ['value' => '100.00', 'quantity' => 1],
            ['value' => '10.00', 'quantity' => 1],
            ['value' => '5.00', 'quantity' => 1],
            ['value' => '2.00', 'quantity' => 1],
            ['value' => '1.00', 'quantity' => 1],
            ['value' => '0.50', 'quantity' => 1],
        ], false);

        $this->assertSame('120.00', $reconciliation['expected_amount']);
        $this->assertSame('118.50', $reconciliation['cash_amount']);
        $this->assertSame('-1.50', $reconciliation['difference']);
        $this->assertNull($reconciliation['change_amount']);
        $this->assertSame(DocumentGenerationBlockedException::CASH_MISMATCH, $reconciliation['reason']);
    }

    public function test_document_cash_reconciliation_requires_change_for_excess_over_one_peso(): void
    {
        $reconciliation = $this->breakdown()->documentCashReconciliation('120.00', [
            ['value' => '100.00', 'quantity' => 1],
            ['value' => '20.00', 'quantity' => 1],
            ['value' => '2.00', 'quantity' => 1],
        ], false);

        $this->assertSame('122.00', $reconciliation['cash_amount']);
        $this->assertSame('2.00', $reconciliation['difference']);
        $this->assertNull($reconciliation['change_amount']);
        $this->assertSame(DocumentGenerationBlockedException::CHANGE_REQUIRED, $reconciliation['reason']);
    }

    public function test_document_cash_reconciliation_calculates_declared_customer_change(): void
    {
        $reconciliation = $this->breakdown()->documentCashReconciliation('120.00', [
            ['value' => '100.00', 'quantity' => 1],
            ['value' => '50.00', 'quantity' => 1],
        ], true);

        $this->assertSame('150.00', $reconciliation['cash_amount']);
        $this->assertSame('30.00', $reconciliation['difference']);
        $this->assertSame('30.00', $reconciliation['change_amount']);
        $this->assertNull($reconciliation['reason']);
    }

    public function test_document_cash_reconciliation_does_not_print_change_for_one_peso_or_less_even_when_declared(): void
    {
        $reconciliation = $this->breakdown()->documentCashReconciliation('120.00', [
            ['value' => '100.00', 'quantity' => 1],
            ['value' => '20.00', 'quantity' => 1],
            ['value' => '1.00', 'quantity' => 1],
        ], true);

        $this->assertSame('1.00', $reconciliation['difference']);
        $this->assertNull($reconciliation['change_amount']);
        $this->assertNull($reconciliation['reason']);
    }

    private function breakdown(): CashBreakdown
    {
        return new CashBreakdown;
    }
}
