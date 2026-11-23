<?php

namespace Tests\Unit\Deliveries;

use App\Domain\Deliveries\DeliveryReturnPolicy;
use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryReturnPolicyTest extends TestCase
{
    use RefreshDatabase;

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

        $returnPolicy = $this->returnPolicy();

        $this->assertSame('150.30', $returnPolicy->currentReturnAmount($record));
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
