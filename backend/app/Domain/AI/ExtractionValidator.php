<?php

namespace App\Domain\AI;

use App\Domain\AI\DTO\AiExtractionResult;
use App\Domain\AI\DTO\AiExtractionValidationResult;
use App\Domain\AI\DTO\ExtractionIssue;
use App\Domain\AI\Normalization\ExtractedValueParser;

class ExtractionValidator
{
    public function __construct(private readonly ExtractedValueParser $parser = new ExtractedValueParser) {}

    private const CONFIDENCE_THRESHOLD = 0.70;

    /** @var array<string, list<string>> */
    private const RECORD_FIELDS = [
        'company_name' => ['string', 'null'],
        'year' => ['integer', 'null'],
        'week' => ['integer', 'null'],
        'week_start_date' => ['string', 'null'],
        'week_end_date' => ['string', 'null'],
    ];

    /** @var array<string, list<string>> */
    private const DETAIL_FIELDS = [
        'affiliation' => ['string', 'null'],
        'branch' => ['string', 'null'],
        'income' => ['string', 'null'],
        'commission_percentage' => ['string', 'null'],
        'source_commission' => ['string', 'null'],
    ];

    /** @var array<string, list<string>> */
    private const TOTAL_FIELDS = [
        'income' => ['string', 'null'],
        'commission' => ['string', 'null'],
        'return_amount' => ['string', 'null'],
    ];

    /**
     * Validate and normalize a provider result. Structural failures are returned as a
     * non-usable outcome so callers can persist raw evidence and diagnostics safely.
     */
    public function validate(AiExtractionResult $result): AiExtractionValidationResult
    {
        $decoded = $result->decodedResponse;
        if ($decoded === null) {
            return $this->structuralFailure('The provider response is not valid JSON.');
        }

        $structureIssues = [];
        $this->checkKeys($decoded, ['record', 'details', 'source_totals'], '', $structureIssues);
        if ($structureIssues !== []) {
            return new AiExtractionValidationResult(false, null, $structureIssues, 0);
        }

        if (! is_array($decoded['record']) || array_is_list($decoded['record'])
            || ! is_array($decoded['details']) || ! array_is_list($decoded['details'])
            || ! is_array($decoded['source_totals']) || array_is_list($decoded['source_totals'])) {
            return $this->structuralFailure('The provider response does not match the extraction structure.');
        }

        $this->checkKeys($decoded['record'], array_keys(self::RECORD_FIELDS), 'record', $structureIssues);
        $this->checkKeys($decoded['source_totals'], array_keys(self::TOTAL_FIELDS), 'source_totals', $structureIssues);
        if ($decoded['details'] === []) {
            $structureIssues[] = ExtractionIssue::error('details', 'EMPTY_DETAILS', 'At least one movement is required.')->toArray();
        }
        foreach ($decoded['details'] as $index => $detail) {
            if (! is_array($detail) || array_is_list($detail)) {
                $structureIssues[] = ExtractionIssue::error("details.{$index}", 'INVALID_DETAIL', 'Each movement must be an object.')->toArray();

                continue;
            }
            $this->checkKeys($detail, array_keys(self::DETAIL_FIELDS), "details.{$index}", $structureIssues);
        }

        if ($structureIssues === []) {
            foreach (self::RECORD_FIELDS as $field => $_types) {
                $this->checkFieldStructure($decoded['record'][$field], "record.{$field}", $structureIssues);
            }
            foreach ($decoded['details'] as $index => $detail) {
                foreach (self::DETAIL_FIELDS as $field => $_types) {
                    $this->checkFieldStructure($detail[$field], "details.{$index}.{$field}", $structureIssues);
                }
            }
            foreach (self::TOTAL_FIELDS as $field => $_types) {
                $this->checkFieldStructure($decoded['source_totals'][$field], "source_totals.{$field}", $structureIssues);
            }
        }

        if ($structureIssues !== []) {
            return new AiExtractionValidationResult(false, null, $structureIssues, 0);
        }

        $issues = [];
        $unconvertibleCount = 0;
        $normalized = [
            'record' => [],
            'details' => [],
            'source_totals' => [],
        ];

        foreach (self::RECORD_FIELDS as $field => $types) {
            $path = "record.{$field}";
            $value = $this->readField($decoded['record'][$field], $path, $types, $issues);
            if ($field === 'year' || $field === 'week') {
                $value = $this->parser->normalizeInteger($value, $field, $path, $issues);
            } elseif ($field === 'week_start_date' || $field === 'week_end_date') {
                $value = $this->parser->normalizeDate($value, $path, $issues);
            }
            $normalized['record'][$field] = $this->field($value, $decoded['record'][$field]['confidence']);
        }

        foreach ($decoded['details'] as $index => $detail) {
            $normalizedDetail = [];
            foreach (self::DETAIL_FIELDS as $field => $types) {
                $path = "details.{$index}.{$field}";
                $value = $this->readField($detail[$field], $path, $types, $issues);
                if (in_array($field, ['income', 'commission_percentage', 'source_commission'], true)) {
                    $value = $this->parser->normalizeNumber($value, $field === 'commission_percentage', $path, $issues, $unconvertibleCount);
                }
                $normalizedDetail[$field] = $this->field($value, $detail[$field]['confidence']);
            }
            $normalized['details'][] = $normalizedDetail;
        }

        foreach (self::TOTAL_FIELDS as $field => $types) {
            $path = "source_totals.{$field}";
            $value = $this->readField($decoded['source_totals'][$field], $path, $types, $issues);
            $value = $this->parser->normalizeNumber($value, false, $path, $issues, $unconvertibleCount);
            $normalized['source_totals'][$field] = $this->field($value, $decoded['source_totals'][$field]['confidence']);
        }

        foreach ($issues as $issue) {
            if ($issue['severity'] === ExtractionIssue::ERROR) {
                return new AiExtractionValidationResult(false, null, $issues, 0);
            }
        }

        return new AiExtractionValidationResult(true, $normalized, $issues, $unconvertibleCount);
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<string>  $requiredKeys
     * @param  list<array{path: string, code: string, severity: string, message: string, value?: mixed}>  $issues
     */
    private function checkKeys(array $object, array $requiredKeys, string $prefix, array &$issues): void
    {
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $object)) {
                $issues[] = ExtractionIssue::error($prefix === '' ? $key : "{$prefix}.{$key}", 'MISSING_PROPERTY', 'A required property is missing.')->toArray();
            }
        }

        foreach (array_diff(array_keys($object), $requiredKeys) as $key) {
            $issues[] = ExtractionIssue::error($prefix === '' ? (string) $key : "{$prefix}.{$key}", 'UNEXPECTED_PROPERTY', 'An unexpected property was returned.')->toArray();
        }
    }

    /**
     * Read a field whose wrapper and confidence checkFieldStructure() already accepted.
     *
     * @param  list<string>  $expectedTypes
     */
    private function readField(array $field, string $path, array $expectedTypes, array &$issues): mixed
    {
        $confidence = (float) $field['confidence'];
        if ($confidence < self::CONFIDENCE_THRESHOLD && $field['value'] !== null) {
            $issues[] = ExtractionIssue::review($path, 'LOW_CONFIDENCE', 'The extracted value has low confidence.')->withValue($field['value'])->toArray();
        }

        if ($field['value'] === null) {
            $issues[] = ExtractionIssue::review($path, 'MISSING_VALUE', 'The value was not visible or could not be read.')->toArray();

            return null;
        }

        $actualType = match (true) {
            is_string($field['value']) => 'string',
            is_int($field['value']) => 'integer',
            is_float($field['value']) => 'number',
            is_bool($field['value']) => 'boolean',
            default => 'object',
        };

        if (! in_array($actualType, $expectedTypes, true)) {
            $issues[] = ExtractionIssue::review($path, 'INVALID_VALUE_TYPE', 'The value has an unexpected type.')->withValue($field['value'])->toArray();

            return null;
        }

        return $field['value'];
    }

    /**
     * @param  list<array{path: string, code: string, severity: string, message: string, value?: mixed}>  $issues
     */
    private function checkFieldStructure(mixed $field, string $path, array &$issues): void
    {
        if (! $this->hasValidWrapper($field, $path, $issues)) {
            return;
        }

        $confidence = $field['confidence'];
        if ((! is_int($confidence) && ! is_float($confidence)) || ! is_finite((float) $confidence)
            || $confidence < 0 || $confidence > 1 || ($field['value'] === null && (float) $confidence !== 0.0)) {
            $issues[] = ExtractionIssue::error("{$path}.confidence", 'INVALID_CONFIDENCE', 'Confidence must be between 0 and 1, and null values require confidence 0.')->toArray();
        }
    }

    /** Whether the field is an object with exactly `value` and `confidence`; adds an INVALID_WRAPPER issue if not. */
    private function hasValidWrapper(mixed $field, string $path, array &$issues): bool
    {
        $keys = is_array($field) && ! array_is_list($field) ? array_keys($field) : null;
        if ($keys !== null) {
            sort($keys);
        }

        if ($keys === ['confidence', 'value']) {
            return true;
        }

        $issues[] = ExtractionIssue::error($path, 'INVALID_WRAPPER', $keys === null
            ? 'The field wrapper must contain value and confidence.'
            : 'The field wrapper must contain exactly value and confidence.')->toArray();

        return false;
    }

    /** @return array{value: mixed, confidence: int|float} */
    private function field(mixed $value, int|float $confidence): array
    {
        return ['value' => $value, 'confidence' => $value === null ? 0 : $confidence];
    }

    private function structuralFailure(string $message): AiExtractionValidationResult
    {
        return new AiExtractionValidationResult(
            structurallyValid: false,
            normalizedExtraction: null,
            issues: [ExtractionIssue::error('$', 'INVALID_EXTRACTION_STRUCTURE', $message)->toArray()],
            unconvertibleNumericCount: 0,
        );
    }
}
