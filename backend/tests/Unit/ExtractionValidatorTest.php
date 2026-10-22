<?php

namespace Tests\Unit;

use App\Domain\AI\DTO\AiExtractionResult;
use App\Domain\AI\ExtractionValidator;
use Tests\TestCase;

class ExtractionValidatorTest extends TestCase
{
    public function test_it_normalizes_supported_numeric_formats_without_mutating_raw_result(): void
    {
        $extraction = $this->extraction();
        $extraction['details'][0]['income']['value'] = '$1,234.56';
        $extraction['details'][0]['commission_percentage']['value'] = '5%';
        $extraction['details'][0]['source_commission']['value'] = '1.234,56';
        $rawResponse = json_encode($extraction, JSON_THROW_ON_ERROR);

        $result = $this->validator()->validate($this->makeResult($extraction, $rawResponse));

        $this->assertTrue($result->isUsable());
        $this->assertSame(0, $result->unconvertibleNumericCount);
        $this->assertSame('1234.56', $result->normalizedExtraction['details'][0]['income']['value']);
        $this->assertSame('5.00', $result->normalizedExtraction['details'][0]['commission_percentage']['value']);
        $this->assertSame('1234.56', $result->normalizedExtraction['details'][0]['source_commission']['value']);
        $this->assertSame('$1,234.56', $extraction['details'][0]['income']['value']);
        $this->assertSame($rawResponse, $this->makeResult($extraction, $rawResponse)->rawResponse);
    }

    public function test_it_rejects_ambiguous_and_malformed_numeric_values_without_guessing(): void
    {
        $extraction = $this->extraction();
        $extraction['details'][0]['income']['value'] = '1,200';
        $extraction['details'][0]['source_commission']['value'] = '1.200';

        $result = $this->validator()->validate($this->makeResult($extraction));

        $this->assertSame(2, $result->unconvertibleNumericCount);
        $this->assertTrue($result->isUsable());
        $this->assertNull($result->normalizedExtraction['details'][0]['income']['value']);
        $this->assertNull($result->normalizedExtraction['details'][0]['source_commission']['value']);
        $this->assertSame(['UNCONVERTIBLE_NUMERIC', 'UNCONVERTIBLE_NUMERIC'], array_column($result->issues, 'code'));
    }

    public function test_it_counts_numeric_values_globally_and_applies_four_value_boundary(): void
    {
        $four = $this->extraction();
        $four['details'][0]['income']['value'] = '55.963.03';
        $four['details'][0]['source_commission']['value'] = '1,200';
        $four['source_totals']['income']['value'] = '1,200';
        $four['source_totals']['commission']['value'] = '1.200';

        $fourResult = $this->validator()->validate($this->makeResult($four));
        $this->assertSame(4, $fourResult->unconvertibleNumericCount);
        $this->assertTrue($fourResult->isUsable());

        $five = $this->extraction();
        $five['details'][0]['income']['value'] = '55.963.03';
        $five['details'][0]['source_commission']['value'] = '1.200';
        $five['source_totals']['income']['value'] = '1,200';
        $five['source_totals']['commission']['value'] = '1.200';
        $five['source_totals']['return_amount']['value'] = '1,200';

        $fiveResult = $this->validator()->validate($this->makeResult($five));
        $this->assertSame(5, $fiveResult->unconvertibleNumericCount);
        $this->assertFalse($fiveResult->isUsable());
    }

    public function test_it_keeps_nulls_as_valid_missing_values_and_reports_low_confidence(): void
    {
        $extraction = $this->extraction();
        $extraction['record']['company_name'] = ['value' => null, 'confidence' => 0];
        $extraction['details'][0]['branch']['confidence'] = 0.5;

        $result = $this->validator()->validate($this->makeResult($extraction));

        $this->assertTrue($result->isUsable());
        $this->assertSame(0, $result->unconvertibleNumericCount);
        $this->assertNull($result->normalizedExtraction['record']['company_name']['value']);
        $this->assertContains('MISSING_VALUE', array_column($result->issues, 'code'));
        $this->assertContains('LOW_CONFIDENCE', array_column($result->issues, 'code'));
    }

    public function test_it_rejects_non_iso_or_impossible_dates_as_review_issues(): void
    {
        foreach (['09/14/2026', '2026-02-30'] as $invalidDate) {
            $extraction = $this->extraction();
            $extraction['record']['week_start_date']['value'] = $invalidDate;

            $result = $this->validator()->validate($this->makeResult($extraction));

            $this->assertTrue($result->isUsable());
            $this->assertNull($result->normalizedExtraction['record']['week_start_date']['value']);
            $this->assertContains(
                $invalidDate === '09/14/2026' ? 'INVALID_DATE_FORMAT' : 'INVALID_DATE',
                array_column($result->issues, 'code'),
            );
        }
    }

    public function test_it_treats_invalid_json_missing_properties_wrappers_and_confidence_as_structural_errors(): void
    {
        $invalidJson = new AiExtractionResult('ollama', 'qwen2.5vl:3b', 'v5', '{', '{', null);
        $invalidJsonResult = $this->validator()->validate($invalidJson);
        $this->assertFalse($invalidJsonResult->structurallyValid);
        $this->assertSame(0, $invalidJsonResult->unconvertibleNumericCount);

        $missing = $this->extraction();
        unset($missing['record']['year']);
        $this->assertFalse($this->validator()->validate($this->makeResult($missing))->structurallyValid);

        $wrapper = $this->extraction();
        unset($wrapper['record']['week']['confidence']);
        $wrapperResult = $this->validator()->validate($this->makeResult($wrapper));
        $this->assertFalse($wrapperResult->structurallyValid);
        $this->assertSame(0, $wrapperResult->unconvertibleNumericCount);

        $confidence = $this->extraction();
        $confidence['record']['company_name']['confidence'] = 1.1;
        $confidenceResult = $this->validator()->validate($this->makeResult($confidence));
        $this->assertFalse($confidenceResult->structurallyValid);
        $this->assertSame(0, $confidenceResult->unconvertibleNumericCount);
    }

    public function test_it_requires_nonempty_details_and_rejects_unexpected_properties(): void
    {
        $emptyDetails = $this->extraction();
        $emptyDetails['details'] = [];
        $this->assertFalse($this->validator()->validate($this->makeResult($emptyDetails))->structurallyValid);

        $unexpected = $this->extraction();
        $unexpected['extra'] = true;
        $this->assertFalse($this->validator()->validate($this->makeResult($unexpected))->structurallyValid);
    }

    private function validator(): ExtractionValidator
    {
        return new ExtractionValidator;
    }

    /** @param array<string, mixed>|null $extraction */
    private function makeResult(?array $extraction, ?string $rawResponse = null): AiExtractionResult
    {
        $rawResponse ??= json_encode($extraction, JSON_THROW_ON_ERROR);

        return new AiExtractionResult('ollama', 'qwen2.5vl:3b', 'v5', $rawResponse, $rawResponse, $extraction);
    }

    /** @return array<string, mixed> */
    private function extraction(): array
    {
        return [
            'record' => [
                'company_name' => ['value' => 'Example Co', 'confidence' => 0.95],
                'year' => ['value' => 2026, 'confidence' => 0.95],
                'week' => ['value' => 38, 'confidence' => 0.95],
                'week_start_date' => ['value' => '2026-09-14', 'confidence' => 0.95],
                'week_end_date' => ['value' => '2026-09-20', 'confidence' => 0.95],
            ],
            'details' => [[
                'affiliation' => ['value' => 'AFF-123', 'confidence' => 0.95],
                'branch' => ['value' => 'Main', 'confidence' => 0.95],
                'income' => ['value' => '10000.00', 'confidence' => 0.95],
                'commission_percentage' => ['value' => '2.00', 'confidence' => 0.95],
                'source_commission' => ['value' => '200.00', 'confidence' => 0.95],
            ]],
            'source_totals' => [
                'income' => ['value' => '10000.00', 'confidence' => 0.95],
                'commission' => ['value' => '200.00', 'confidence' => 0.95],
                'return_amount' => ['value' => '9800.00', 'confidence' => 0.95],
            ],
        ];
    }
}
