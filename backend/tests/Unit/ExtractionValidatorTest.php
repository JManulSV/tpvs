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
        $extraction['record']['commission_percentage']['value'] = '6.5%';
        $extraction['source_totals']['commission']['value'] = '1.234,56';
        $rawResponse = json_encode($extraction, JSON_THROW_ON_ERROR);

        $result = $this->validator()->validate($this->makeResult($extraction, $rawResponse));

        $this->assertTrue($result->isUsable());
        $this->assertSame(0, $result->unconvertibleNumericCount);
        $this->assertSame('1234.56', $result->normalizedExtraction['details'][0]['income']['value']);
        $this->assertSame('6.50', $result->normalizedExtraction['record']['commission_percentage']['value']);
        $this->assertSame('1234.56', $result->normalizedExtraction['source_totals']['commission']['value']);
        $this->assertSame('$1,234.56', $extraction['details'][0]['income']['value']);
        $this->assertSame($rawResponse, $this->makeResult($extraction, $rawResponse)->rawResponse);
    }

    public function test_it_rejects_ambiguous_and_malformed_numeric_values_without_guessing(): void
    {
        $extraction = $this->extraction();
        $extraction['details'][0]['income']['value'] = '1,200';
        $extraction['record']['commission_percentage']['value'] = '1.200';

        $result = $this->validator()->validate($this->makeResult($extraction));

        $this->assertSame(2, $result->unconvertibleNumericCount);
        $this->assertTrue($result->isUsable());
        $this->assertNull($result->normalizedExtraction['details'][0]['income']['value']);
        $this->assertNull($result->normalizedExtraction['record']['commission_percentage']['value']);
        $this->assertSame(['UNCONVERTIBLE_NUMERIC', 'UNCONVERTIBLE_NUMERIC'], array_column($result->issues, 'code'));
    }

    public function test_it_counts_numeric_values_globally_and_applies_four_value_boundary(): void
    {
        $four = $this->extraction();
        $four['details'][0]['income']['value'] = '55.963.03';
        $four['record']['commission_percentage']['value'] = '1,200';
        $four['source_totals']['income']['value'] = '1,200';
        $four['source_totals']['commission']['value'] = '1.200';

        $fourResult = $this->validator()->validate($this->makeResult($four));
        $this->assertSame(4, $fourResult->unconvertibleNumericCount);
        $this->assertTrue($fourResult->isUsable());

        $five = $this->extraction();
        $five['details'][0]['income']['value'] = '55.963.03';
        $five['record']['commission_percentage']['value'] = '1.200';
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
        $extraction['details'][0]['bank']['confidence'] = 0.5;

        $result = $this->validator()->validate($this->makeResult($extraction));

        $this->assertTrue($result->isUsable());
        $this->assertSame(0, $result->unconvertibleNumericCount);
        $this->assertNull($result->normalizedExtraction['record']['company_name']['value']);
        $this->assertContains('MISSING_VALUE', array_column($result->issues, 'code'));
        $this->assertContains('LOW_CONFIDENCE', array_column($result->issues, 'code'));
    }

    public function test_it_rejects_non_iso_or_impossible_dates_as_review_issues(): void
    {
        foreach (['week_start_date', 'completion_date'] as $field) {
            foreach (['23/11/2026', '2026-02-30'] as $invalidDate) {
                $extraction = $this->extraction();
                $extraction['record'][$field]['value'] = $invalidDate;

                $result = $this->validator()->validate($this->makeResult($extraction));

                $this->assertTrue($result->isUsable());
                $this->assertNull($result->normalizedExtraction['record'][$field]['value']);
                $this->assertContains(
                    $invalidDate === '23/11/2026' ? 'INVALID_DATE_FORMAT' : 'INVALID_DATE',
                    array_column($result->issues, 'code'),
                );
            }
        }
    }

    public function test_it_keeps_the_v6_header_fields_in_the_normalized_extraction(): void
    {
        $result = $this->validator()->validate($this->makeResult($this->extraction()));

        $this->assertTrue($result->isUsable());
        $this->assertSame([], $result->issues);
        $this->assertSame(['value' => '18006', 'confidence' => 0.95], $result->normalizedExtraction['record']['document_number']);
        $this->assertSame(['value' => '2026-11-23', 'confidence' => 0.9], $result->normalizedExtraction['record']['completion_date']);
        $this->assertSame(['value' => '6.50', 'confidence' => 0.9], $result->normalizedExtraction['record']['commission_percentage']);
        $this->assertSame(
            ['affiliation' => ['value' => '8889067', 'confidence' => 0.95], 'bank' => ['value' => 'LUUM', 'confidence' => 0.95], 'income' => ['value' => '236741.30', 'confidence' => 0.95]],
            $result->normalizedExtraction['details'][0],
        );
    }

    public function test_the_document_number_must_be_a_string(): void
    {
        $extraction = $this->extraction();
        $extraction['record']['document_number']['value'] = 18006;

        $result = $this->validator()->validate($this->makeResult($extraction));

        $this->assertTrue($result->isUsable());
        $this->assertNull($result->normalizedExtraction['record']['document_number']['value']);
        $this->assertContains('INVALID_VALUE_TYPE', array_column($result->issues, 'code'));
    }

    public function test_the_v5_movement_fields_are_rejected_as_unexpected(): void
    {
        foreach (['branch', 'commission_percentage', 'source_commission'] as $legacyField) {
            $extraction = $this->extraction();
            $extraction['details'][0][$legacyField] = ['value' => 'x', 'confidence' => 0.9];

            $result = $this->validator()->validate($this->makeResult($extraction));

            $this->assertFalse($result->structurallyValid, $legacyField);
            $this->assertContains("details.0.{$legacyField}", array_column($result->issues, 'path'));
        }
    }

    public function test_it_treats_invalid_json_missing_properties_wrappers_and_confidence_as_structural_errors(): void
    {
        $invalidJson = new AiExtractionResult('ollama', 'qwen2.5vl:3b', 'v6', '{', '{', null);
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

        return new AiExtractionResult('ollama', 'qwen2.5vl:3b', 'v6', $rawResponse, $rawResponse, $extraction);
    }

    /** @return array<string, mixed> */
    private function extraction(): array
    {
        return [
            // The v6 contract, with the values of test-images/nuevo-formato.png.
            'record' => [
                'company_name' => ['value' => 'Villaseñor', 'confidence' => 0.95],
                'document_number' => ['value' => '18006', 'confidence' => 0.95],
                'completion_date' => ['value' => '2026-11-23', 'confidence' => 0.9],
                'year' => ['value' => 2026, 'confidence' => 0.95],
                'week' => ['value' => 46, 'confidence' => 0.95],
                'week_start_date' => ['value' => '2026-11-16', 'confidence' => 0.9],
                'week_end_date' => ['value' => '2026-11-22', 'confidence' => 0.9],
                'commission_percentage' => ['value' => '6.5%', 'confidence' => 0.9],
            ],
            'details' => [[
                'affiliation' => ['value' => '8889067', 'confidence' => 0.95],
                'bank' => ['value' => 'LUUM', 'confidence' => 0.95],
                'income' => ['value' => '$ 236,741.30', 'confidence' => 0.95],
            ]],
            'source_totals' => [
                'income' => ['value' => '$ 236,741.30', 'confidence' => 0.95],
                'commission' => ['value' => '$ 15,388.18', 'confidence' => 0.95],
                'return_amount' => ['value' => '$ 221,353.12', 'confidence' => 0.95],
            ],
        ];
    }
}
