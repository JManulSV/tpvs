<?php

namespace Tests\Feature;

use App\Domain\AI\ExtractionFinancialCalculator;
use App\Domain\AI\RecordExtractionService;
use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\Company;
use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_company_exactly_and_persists_ordered_financial_proposals(): void
    {
        $company = Company::factory()->create(['name' => '  Example Co  ']);
        $record = $this->imageRecord();

        $issues = $this->service()->apply($record, $this->extraction(' example CO '));
        $record->refresh()->load('details');

        $this->assertSame($company->id, $record->company_id);
        $this->assertSame(2026, $record->year);
        $this->assertSame(38, $record->week);
        $this->assertSame([1, 2], $record->details->pluck('order')->all());
        $this->assertSame(['AFF-1', 'AFF-2'], $record->details->pluck('affiliation')->all());
        $this->assertSame(['200.00', '100.00'], $record->details->pluck('commission')->all());
        $this->assertSame(['9800.00', '1900.00'], $record->details->pluck('return_amount')->all());
        $this->assertSame([null, null], $record->details->pluck('operation_number')->all());
        $this->assertContains('OPERATION_NUMBER_MISSING', array_column($issues, 'code'));
        $this->assertContains('SOURCE_COMMISSION_MISMATCH', array_column($issues, 'code'));
        $this->assertContains('SOURCE_RETURN_MISMATCH', array_column($issues, 'code'));
        $this->assertContains('SOURCE_TOTAL_MISMATCH', array_column($issues, 'code'));
        $this->assertSame(RecordStatus::PROCESSING, $record->status);
    }

    public function test_it_leaves_company_unresolved_for_accent_or_punctuation_mismatches(): void
    {
        Company::factory()->create(['name' => 'Compañía, S.A.']);
        $record = $this->imageRecord();

        $issues = $this->service()->apply($record, $this->extraction('Compania SA'));

        $this->assertNull($record->fresh()->company_id);
        $this->assertContains('COMPANY_NOT_FOUND', array_column($issues, 'code'));
    }

    public function test_it_leaves_company_unresolved_when_exact_match_is_ambiguous(): void
    {
        Company::factory()->create(['name' => 'Acme']);
        Company::factory()->create(['name' => ' ACME ']);
        $record = $this->imageRecord();

        $issues = $this->service()->apply($record, $this->extraction('Acme'));

        $this->assertNull($record->fresh()->company_id);
        $this->assertContains('COMPANY_AMBIGUOUS', array_column($issues, 'code'));
    }

    public function test_it_does_not_persist_values_outside_schema_constraints(): void
    {
        $record = $this->imageRecord();
        $extraction = $this->extraction('Unknown');
        $extraction['details'][0]['income']['value'] = '10000000000.00';
        $extraction['details'][0]['commission_percentage']['value'] = '101.00';
        $extraction['details'][0]['source_commission']['value'] = '500.00';

        $issues = $this->service()->apply($record, $extraction);
        $detail = $record->fresh()->details()->firstOrFail();

        $this->assertNull($detail->income);
        $this->assertNull($detail->commission_percentage);
        $this->assertNull($detail->commission);
        $this->assertContains('MONEY_OUT_OF_RANGE', array_column($issues, 'code'));
        $this->assertContains('PERCENTAGE_OUT_OF_RANGE', array_column($issues, 'code'));
    }

    private function service(): RecordExtractionService
    {
        return new RecordExtractionService(new ExtractionFinancialCalculator);
    }

    private function imageRecord(): Record
    {
        return Record::factory()->create([
            'company_id' => null,
            'source' => RecordSource::IMAGE,
            'status' => RecordStatus::PROCESSING,
            'year' => null,
            'week' => null,
            'week_start_date' => null,
            'week_end_date' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function extraction(string $companyName): array
    {
        return [
            'record' => [
                'company_name' => ['value' => $companyName, 'confidence' => 0.9],
                'year' => ['value' => 2026, 'confidence' => 0.9],
                'week' => ['value' => 38, 'confidence' => 0.9],
                'week_start_date' => ['value' => '2026-09-14', 'confidence' => 0.9],
                'week_end_date' => ['value' => '2026-09-20', 'confidence' => 0.9],
            ],
            'details' => [
                [
                    'affiliation' => ['value' => 'AFF-1', 'confidence' => 0.9],
                    'branch' => ['value' => 'North', 'confidence' => 0.9],
                    'income' => ['value' => '10000.00', 'confidence' => 0.9],
                    'commission_percentage' => ['value' => '2.00', 'confidence' => 0.9],
                    'source_commission' => ['value' => '199.00', 'confidence' => 0.9],
                    'source_return_amount' => ['value' => '9801.00', 'confidence' => 0.9],
                ],
                [
                    'affiliation' => ['value' => 'AFF-2', 'confidence' => 0.9],
                    'branch' => ['value' => 'South', 'confidence' => 0.9],
                    'income' => ['value' => '2000.00', 'confidence' => 0.9],
                    'commission_percentage' => ['value' => null, 'confidence' => 0],
                    'source_commission' => ['value' => '100.00', 'confidence' => 0.9],
                    'source_return_amount' => ['value' => '1900.00', 'confidence' => 0.9],
                ],
            ],
            'source_totals' => [
                'income' => ['value' => '12000.00', 'confidence' => 0.9],
                'commission' => ['value' => '299.00', 'confidence' => 0.9],
                'return_amount' => ['value' => '11701.00', 'confidence' => 0.9],
            ],
        ];
    }
}
