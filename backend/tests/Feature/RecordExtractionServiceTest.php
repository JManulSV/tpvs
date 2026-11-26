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
        $this->assertSame(['North', 'South'], $record->details->pluck('bank')->all());
        $this->assertSame(['10000.00', '2000.00'], $record->details->pluck('income')->all());
        // 12000.00 × 2% = 240.00 once over the total.
        $this->assertSame(['2.00', '240.00', '11760.00'], [$record->commission_percentage, $record->commission, $record->return_amount]);
        $mismatches = array_values(array_filter($issues, static fn (array $issue): bool => $issue['code'] === 'SOURCE_TOTAL_MISMATCH'));
        $this->assertSame(['source_totals.commission', 'source_totals.return_amount'], array_column($mismatches, 'path'));
        $this->assertSame(RecordStatus::PROCESSING, $record->status);
    }

    public function test_header_fields_are_not_copied_to_the_record(): void
    {
        $record = $this->imageRecord();

        $this->service()->apply($record, $this->extraction('Unknown'));

        $attributes = $record->fresh()->getAttributes();
        $this->assertArrayNotHasKey('document_number', $attributes);
        $this->assertArrayNotHasKey('completion_date', $attributes);
    }

    public function test_a_missing_income_leaves_the_totals_null_but_keeps_the_movements(): void
    {
        $record = $this->imageRecord();
        $extraction = $this->extraction('Unknown');
        $extraction['details'][1]['income'] = ['value' => null, 'confidence' => 0];

        $issues = $this->service()->apply($record, $extraction);
        $record->refresh()->load('details');

        $this->assertSame(['10000.00', null], $record->details->pluck('income')->all());
        $this->assertSame('2.00', $record->commission_percentage);
        $this->assertNull($record->commission);
        $this->assertNull($record->return_amount);
        $this->assertNotContains('SOURCE_TOTAL_MISMATCH', array_column($issues, 'code'));
    }

    public function test_a_total_that_does_not_fit_the_record_leaves_the_totals_null(): void
    {
        $record = $this->imageRecord();
        $extraction = $this->extraction('Unknown');
        $extraction['details'][0]['income']['value'] = '9999999999.99';
        $extraction['details'][1]['income']['value'] = '0.01';

        $issues = $this->service()->apply($record, $extraction);
        $record->refresh();

        $this->assertNull($record->commission);
        $this->assertNull($record->return_amount);
        $this->assertContains('RECORD_TOTAL_OUT_OF_RANGE', array_column($issues, 'code'));
    }

    public function test_it_leaves_company_unresolved_for_accent_or_punctuation_mismatches(): void
    {
        Company::factory()->create(['name' => 'Compañía, S.A.']);
        $record = $this->imageRecord();

        $issues = $this->service()->apply($record, $this->extraction('Compania SA'));

        $this->assertNull($record->fresh()->company_id);
        $this->assertContains('COMPANY_NOT_FOUND', array_column($issues, 'code'));
    }

    public function test_it_leaves_company_unresolved_when_extracted_name_is_blank_after_trim(): void
    {
        Company::factory()->create(['name' => 'Acme']);
        Company::factory()->create(['name' => 'ACME Corporation']);
        $record = $this->imageRecord();

        $extraction = $this->extraction('Acme');
        $extraction['record']['company_name']['value'] = '   ';
        $issues = $this->service()->apply($record, $extraction);

        $this->assertNull($record->fresh()->company_id);
        $this->assertContains('COMPANY_NAME_MISSING', array_column($issues, 'code'));
    }

    public function test_it_does_not_persist_values_outside_schema_constraints(): void
    {
        $record = $this->imageRecord();
        $extraction = $this->extraction('Unknown');
        $extraction['details'][0]['income']['value'] = '10000000000.00';
        $extraction['record']['commission_percentage']['value'] = '101.00';

        $issues = $this->service()->apply($record, $extraction);
        $fresh = $record->fresh();

        $this->assertNull($fresh->details()->firstOrFail()->income);
        $this->assertNull($fresh->commission_percentage);
        $this->assertNull($fresh->commission);
        $this->assertNull($fresh->return_amount);
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
                'document_number' => ['value' => '18006', 'confidence' => 0.9],
                'completion_date' => ['value' => '2026-09-21', 'confidence' => 0.9],
                'commission_percentage' => ['value' => '2.00', 'confidence' => 0.9],
            ],
            'details' => [
                [
                    'affiliation' => ['value' => 'AFF-1', 'confidence' => 0.9],
                    'bank' => ['value' => 'North', 'confidence' => 0.9],
                    'income' => ['value' => '10000.00', 'confidence' => 0.9],
                ],
                [
                    'affiliation' => ['value' => 'AFF-2', 'confidence' => 0.9],
                    'bank' => ['value' => 'South', 'confidence' => 0.9],
                    'income' => ['value' => '2000.00', 'confidence' => 0.9],
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
