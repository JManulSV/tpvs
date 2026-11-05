<?php

namespace Tests\Unit;

use App\Domain\Deliveries\DeliveryValidator;
use App\Domain\Documents\Exceptions\DocumentGenerationBlockedException;
use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DeliveryValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recognizes_only_the_official_denominations(): void
    {
        $validator = $this->validator();

        foreach (['1000', '1000.0', '1000.00', '0.5', '0.50', '1'] as $value) {
            $this->assertTrue($validator->isOfficialDenomination($value), $value);
        }

        foreach (['25.00', '0.25', '-1000.00', '1000.000', 'abc'] as $value) {
            $this->assertFalse($validator->isOfficialDenomination($value), $value);
        }
    }

    public function test_it_drops_zero_quantities_and_sorts_denominations_descending(): void
    {
        $canonical = $this->validator()->canonicalizeDenominations([
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

    public function test_it_calculates_the_cash_total_in_cents(): void
    {
        $validator = $this->validator();

        $this->assertSame(350, $validator->cashTotalCents([
            ['value' => '0.50', 'quantity' => 3],
            ['value' => '1', 'quantity' => 2],
        ]));
        $this->assertSame('3.50', $validator->cashTotal([
            ['value' => '0.50', 'quantity' => 3],
            ['value' => '1', 'quantity' => 2],
        ]));
    }

    public function test_it_calculates_the_current_return_from_record_details(): void
    {
        $record = Record::factory()->create();
        $record->details()->createMany([
            [
                'affiliation' => 'AFF-1',
                'branch' => 'Guadalajara',
                'income' => '110.10',
                'commission' => '10.00',
                'return_amount' => '100.10',
                'order' => 1,
            ],
            [
                'affiliation' => 'AFF-2',
                'branch' => 'Zapopan',
                'income' => '60.20',
                'commission' => '10.00',
                'return_amount' => '50.20',
                'order' => 2,
            ],
        ]);

        $validator = $this->validator();

        $this->assertSame(15030, $validator->currentReturnCents($record));
        $this->assertSame('150.30', $validator->currentReturnAmount($record));
    }

    public function test_it_detects_a_cash_mismatch_with_expected_cash_and_difference(): void
    {
        $validator = $this->validator();

        $this->assertNull($validator->cashMismatch('100.00', [
            ['value' => '20.00', 'quantity' => 5],
        ]));

        $this->assertSame([
            'expected_amount' => '100.00',
            'cash_amount' => '80.50',
            'difference' => '-19.50',
        ], $validator->cashMismatch('100.00', [
            ['value' => '20.00', 'quantity' => 4],
            ['value' => '0.50', 'quantity' => 1],
        ]));
    }

    public function test_it_derives_the_three_document_return_options_from_the_official_return(): void
    {
        $validator = $this->validator();

        $this->assertSame(
            ['1298.00', '1298.50', '1299.00'],
            $validator->documentReturnOptions('1298.57'),
        );
        $this->assertSame(
            ['1298.00', '1298.50', '1299.00'],
            $validator->documentReturnOptions('1298.99'),
        );
        $this->assertSame(
            ['1298.00', '1298.50', '1299.00'],
            $validator->documentReturnOptions('1298.00'),
        );
    }

    public function test_it_accepts_only_document_amounts_derived_from_the_official_return(): void
    {
        $validator = $this->validator();

        $this->assertTrue($validator->isDocumentReturnOption('1298.57', '1298'));
        $this->assertTrue($validator->isDocumentReturnOption('1298.57', '1298.50'));
        $this->assertTrue($validator->isDocumentReturnOption('1298.57', '1299.00'));
        $this->assertFalse($validator->isDocumentReturnOption('1298.57', '1298.57'));
        $this->assertFalse($validator->isDocumentReturnOption('1298.57', '1300.00'));
        $this->assertFalse($validator->isDocumentReturnOption('1298.57', '-1.00'));
        $this->assertFalse($validator->isDocumentReturnOption('1298.57', 'invalid'));
    }

    public function test_document_cash_reconciliation_accepts_exact_one_peso_differences_without_change(): void
    {
        $validator = $this->validator();

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
            $reconciliation = $validator->documentCashReconciliation('120.00', $denominations, false);

            $this->assertSame('120.00', $reconciliation['expected_amount']);
            $this->assertSame($cash, $reconciliation['cash_amount']);
            $this->assertSame($difference, $reconciliation['difference']);
            $this->assertNull($reconciliation['change_amount']);
            $this->assertNull($reconciliation['reason']);
        }
    }

    public function test_document_cash_reconciliation_blocks_shortfalls_over_one_peso(): void
    {
        $reconciliation = $this->validator()->documentCashReconciliation('120.00', [
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
        $reconciliation = $this->validator()->documentCashReconciliation('120.00', [
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
        $reconciliation = $this->validator()->documentCashReconciliation('120.00', [
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
        $reconciliation = $this->validator()->documentCashReconciliation('120.00', [
            ['value' => '100.00', 'quantity' => 1],
            ['value' => '20.00', 'quantity' => 1],
            ['value' => '1.00', 'quantity' => 1],
        ], true);

        $this->assertSame('1.00', $reconciliation['difference']);
        $this->assertNull($reconciliation['change_amount']);
        $this->assertNull($reconciliation['reason']);
    }

    public function test_it_detects_stale_preparations_after_canonicalizing_amounts(): void
    {
        $validator = $this->validator();

        $this->assertFalse($validator->isStale('9800', '9800.00'));
        $this->assertTrue($validator->isStale('9800.00', '10000.00'));
    }

    public function test_it_canonicalizes_delivery_history_values(): void
    {
        $validator = $this->validator();

        $this->assertSame('2026-09-18', $validator->canonicalDate('2026-09-18'));
        $this->assertSame('Maria Lopez', $validator->canonicalName('  Maria Lopez  '));
        $this->assertNull($validator->canonicalName('   '));
        $this->assertSame('100.50', $validator->canonicalAmount('00100.5'));
        $this->assertSame('-0.50', $validator->canonicalAmount('-0.5'));
        $this->assertSame('0.00', $validator->canonicalAmount('-0.00'));
        $this->assertSame('5', $validator->canonicalQuantity('5'));
    }

    public function test_it_rejects_unsupported_denominations_when_canonicalizing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator()->canonicalizeDenominations([
            ['value' => '25.00', 'quantity' => 1],
        ]);
    }

    private function validator(): DeliveryValidator
    {
        return new DeliveryValidator;
    }
}
