<?php

namespace Tests\Unit\Deliveries;

use App\Domain\Deliveries\DeliveryReturnPolicy;
use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryReturnPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_current_return_is_the_record_return_not_a_sum_of_movements(): void
    {
        // 3 × 100.10 = 300.30; 300.30 × 6.5% = 19.52; return 280.78.
        $record = Record::factory()->create([
            'commission_percentage' => '6.50',
            'commission' => '19.52',
            'return_amount' => '280.78',
        ]);
        foreach ([1, 2, 3] as $order) {
            $record->details()->create([
                'affiliation' => "AFF-{$order}",
                'bank' => 'LUUM',
                'income' => '100.10',
                'order' => $order,
            ]);
        }

        $this->assertSame('280.78', $this->returnPolicy()->currentReturnAmount($record));
    }

    public function test_a_record_without_a_calculated_return_has_zero_current_return(): void
    {
        $record = Record::factory()->create(['return_amount' => null]);

        $this->assertSame('0.00', $this->returnPolicy()->currentReturnAmount($record));
    }

    public function test_it_derives_the_three_document_return_options_from_the_official_return(): void
    {
        $returnPolicy = $this->returnPolicy();

        $this->assertSame(
            ['1298.00', '1298.50', '1299.00'],
            $returnPolicy->documentReturnOptions('1298.57'),
        );
        $this->assertSame(
            ['1298.00', '1298.50', '1299.00'],
            $returnPolicy->documentReturnOptions('1298.99'),
        );
        $this->assertSame(
            ['1298.00', '1298.50', '1299.00'],
            $returnPolicy->documentReturnOptions('1298.00'),
        );
    }

    public function test_it_accepts_only_document_amounts_derived_from_the_official_return(): void
    {
        $returnPolicy = $this->returnPolicy();

        $this->assertTrue($returnPolicy->isDocumentReturnOption('1298.57', '1298'));
        $this->assertTrue($returnPolicy->isDocumentReturnOption('1298.57', '1298.50'));
        $this->assertTrue($returnPolicy->isDocumentReturnOption('1298.57', '1299.00'));
        $this->assertFalse($returnPolicy->isDocumentReturnOption('1298.57', '1298.57'));
        $this->assertFalse($returnPolicy->isDocumentReturnOption('1298.57', '1300.00'));
        $this->assertFalse($returnPolicy->isDocumentReturnOption('1298.57', '-1.00'));
        $this->assertFalse($returnPolicy->isDocumentReturnOption('1298.57', 'invalid'));
    }

    public function test_it_detects_stale_preparations_after_canonicalizing_amounts(): void
    {
        $returnPolicy = $this->returnPolicy();

        $this->assertFalse($returnPolicy->isStale('9800', '9800.00'));
        $this->assertTrue($returnPolicy->isStale('9800.00', '10000.00'));
    }

    private function returnPolicy(): DeliveryReturnPolicy
    {
        return new DeliveryReturnPolicy;
    }
}
