<?php

namespace App\Domain\AI;

use App\Domain\AI\DTO\AiExtractionResult;
use App\Domain\AI\DTO\AiExtractionValidationResult;
use DateTimeImmutable;

class ExtractionValidator
{
    private const CONFIDENCE_THRESHOLD = 0.70;

    private const NUMERIC_CANDIDATE_PATTERN = '/^[\d.,\s$€£¥₹%+\-]+$/uD';

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
        'source_return_amount' => ['string', 'null'],
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
     *
     * @param  array<string, mixed>|list<mixed>|null  $decoded
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
            $structureIssues[] = $this->issue('details', 'EMPTY_DETAILS', 'error', 'At least one movement is required.');
        }
        foreach ($decoded['details'] as $index => $detail) {
            if (! is_array($detail) || array_is_list($detail)) {
                $structureIssues[] = $this->issue("details.{$index}", 'INVALID_DETAIL', 'error', 'Each movement must be an object.');

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
                $value = $this->normalizeInteger($value, $field, $path, $issues, $unconvertibleCount);
            } elseif ($field === 'week_start_date' || $field === 'week_end_date') {
                $value = $this->normalizeDate($value, $path, $issues);
            }
            $normalized['record'][$field] = $this->field($value, $decoded['record'][$field]['confidence']);
        }

        foreach ($decoded['details'] as $index => $detail) {
            $normalizedDetail = [];
            foreach (self::DETAIL_FIELDS as $field => $types) {
                $path = "details.{$index}.{$field}";
                $value = $this->readField($detail[$field], $path, $types, $issues);
                if (in_array($field, ['income', 'commission_percentage', 'source_commission', 'source_return_amount'], true)) {
                    $value = $this->normalizeNumber($value, $field === 'commission_percentage', $path, $issues, $unconvertibleCount);
                }
                $normalizedDetail[$field] = $this->field($value, $detail[$field]['confidence']);
            }
            $normalized['details'][] = $normalizedDetail;
        }

        foreach (self::TOTAL_FIELDS as $field => $types) {
            $path = "source_totals.{$field}";
            $value = $this->readField($decoded['source_totals'][$field], $path, $types, $issues);
            $value = $this->normalizeNumber($value, false, $path, $issues, $unconvertibleCount);
            $normalized['source_totals'][$field] = $this->field($value, $decoded['source_totals'][$field]['confidence']);
        }

        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
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
                $issues[] = $this->issue($prefix === '' ? $key : "{$prefix}.{$key}", 'MISSING_PROPERTY', 'error', 'A required property is missing.');
            }
        }

        foreach (array_diff(array_keys($object), $requiredKeys) as $key) {
            $issues[] = $this->issue($prefix === '' ? (string) $key : "{$prefix}.{$key}", 'UNEXPECTED_PROPERTY', 'error', 'An unexpected property was returned.');
        }
    }

    /** @param list<string> $expectedTypes */
    private function readField(mixed $field, string $path, array $expectedTypes, array &$issues): mixed
    {
        if (! is_array($field) || array_is_list($field)) {
            $issues[] = $this->issue($path, 'INVALID_WRAPPER', 'error', 'The field wrapper must contain value and confidence.');

            return null;
        }

        $wrapperKeys = array_keys($field);
        sort($wrapperKeys);
        if ($wrapperKeys !== ['confidence', 'value']) {
            $issues[] = $this->issue($path, 'INVALID_WRAPPER', 'error', 'The field wrapper must contain exactly value and confidence.');

            return null;
        }

        if (! is_int($field['confidence']) && ! is_float($field['confidence'])) {
            $issues[] = $this->issue("{$path}.confidence", 'INVALID_CONFIDENCE', 'error', 'Confidence must be a number from 0 to 1.');

            return null;
        }

        $confidence = (float) $field['confidence'];
        if (! is_finite($confidence) || $confidence < 0 || $confidence > 1 || ($field['value'] === null && $confidence !== 0.0)) {
            $issues[] = $this->issue("{$path}.confidence", 'INVALID_CONFIDENCE', 'error', 'Confidence must be between 0 and 1, and null values require confidence 0.');

            return null;
        }

        if ($confidence < self::CONFIDENCE_THRESHOLD && $field['value'] !== null) {
            $issues[] = $this->issue($path, 'LOW_CONFIDENCE', 'review', 'The extracted value has low confidence.', $field['value']);
        }

        if ($field['value'] === null) {
            $issues[] = $this->issue($path, 'MISSING_VALUE', 'review', 'The value was not visible or could not be read.');

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
            $issues[] = $this->issue($path, 'INVALID_VALUE_TYPE', 'review', 'The value has an unexpected type.', $field['value']);

            return null;
        }

        return $field['value'];
    }

    /**
     * @param  list<array{path: string, code: string, severity: string, message: string, value?: mixed}>  $issues
     */
    private function checkFieldStructure(mixed $field, string $path, array &$issues): void
    {
        if (! is_array($field) || array_is_list($field)) {
            $issues[] = $this->issue($path, 'INVALID_WRAPPER', 'error', 'The field wrapper must contain value and confidence.');

            return;
        }

        $wrapperKeys = array_keys($field);
        sort($wrapperKeys);
        if ($wrapperKeys !== ['confidence', 'value']) {
            $issues[] = $this->issue($path, 'INVALID_WRAPPER', 'error', 'The field wrapper must contain exactly value and confidence.');

            return;
        }

        $confidence = $field['confidence'];
        if ((! is_int($confidence) && ! is_float($confidence)) || ! is_finite((float) $confidence)
            || $confidence < 0 || $confidence > 1 || ($field['value'] === null && (float) $confidence !== 0.0)) {
            $issues[] = $this->issue("{$path}.confidence", 'INVALID_CONFIDENCE', 'error', 'Confidence must be between 0 and 1, and null values require confidence 0.');
        }
    }

    private function normalizeInteger(mixed $value, string $field, string $path, array &$issues, int &$count): ?int
    {
        if ($value === null) {
            return null;
        }

        $valid = $field === 'year' ? $value >= 1 && $value <= 9999 : $value >= 1 && $value <= 53;
        if (! $valid) {
            $issues[] = $this->issue($path, $field === 'year' ? 'INVALID_YEAR' : 'INVALID_WEEK', 'review', 'The numeric value is outside the supported range.', $value);

            return null;
        }

        return $value;
    }

    private function normalizeDate(mixed $value, string $path, array &$issues): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $issues[] = $this->issue($path, 'INVALID_DATE_FORMAT', 'review', 'Dates must use ISO YYYY-MM-DD format.', $value);

            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            $issues[] = $this->issue($path, 'INVALID_DATE', 'review', 'The date is not a real calendar date.', $value);

            return null;
        }

        return $value;
    }

    private function normalizeNumber(mixed $value, bool $percentage, string $path, array &$issues, int &$count): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = $this->parseNumber($value, $percentage);
        if ($normalized !== null) {
            return $normalized;
        }

        if (! $this->isNumericCandidate($value)) {
            $issues[] = $this->issue($path, 'INVALID_VALUE_FORMAT', 'review', 'The value does not match the expected numeric format.', $value);

            return null;
        }

        $count++;
        $issues[] = $this->issue($path, 'UNCONVERTIBLE_NUMERIC', 'review', 'The numeric value is ambiguous or malformed and was not normalized.', $value);

        return null;
    }

    private function parseNumber(string $input, bool $percentage): ?string
    {
        $value = trim($input);
        $hasPercent = str_ends_with($value, '%');
        if (! $percentage && $hasPercent) {
            return null;
        }
        if ($hasPercent) {
            $value = trim(substr($value, 0, -1));
        }

        if (! $percentage && preg_match('/^[\$€£¥₹]\s*/u', $value, $currency) === 1) {
            $value = substr($value, strlen($currency[0]));
        }

        if ($value === '' || str_contains($value, '$') || preg_match('/^[0-9.,]+$/D', $value) !== 1) {
            return null;
        }

        if (preg_match('/^\d+$/D', $value) === 1) {
            $whole = $value;
            $fraction = '';
        } elseif (preg_match('/^\d+\.\d{1,2}$/D', $value) === 1) {
            [$whole, $fraction] = explode('.', $value, 2);
        } elseif (preg_match('/^\d{1,3}(?:,\d{3})+\.\d{1,2}$/D', $value) === 1) {
            $withoutGroups = str_replace(',', '', $value);
            [$whole, $fraction] = explode('.', $withoutGroups, 2);
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+,\d{1,2}$/D', $value) === 1) {
            $withoutGroups = str_replace('.', '', $value);
            [$whole, $fraction] = explode(',', $withoutGroups, 2);
        } else {
            return null;
        }

        return $this->formatDecimal($whole, $fraction);
    }

    /**
     * @param  list<array{path: string, code: string, severity: string, message: string, value?: mixed}>  $issues
     */
    private function isNumericCandidate(string $input): bool
    {
        return preg_match(self::NUMERIC_CANDIDATE_PATTERN, trim($input)) === 1;
    }

    private function formatDecimal(string $whole, string $fraction): string
    {
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    /** @return array{value: mixed, confidence: int|float} */
    private function field(mixed $value, int|float $confidence): array
    {
        return ['value' => $value, 'confidence' => $value === null ? 0 : $confidence];
    }

    /**
     * @param  list<array{path: string, code: string, severity: string, message: string, value?: mixed}>  $issues
     */
    private function structuralFailure(string $message): AiExtractionValidationResult
    {
        return new AiExtractionValidationResult(
            structurallyValid: false,
            normalizedExtraction: null,
            issues: [$this->issue('$', 'INVALID_EXTRACTION_STRUCTURE', 'error', $message)],
            unconvertibleNumericCount: 0,
        );
    }

    /** @return array{path: string, code: string, severity: string, message: string, value?: mixed} */
    private function issue(string $path, string $code, string $severity, string $message, mixed $value = null): array
    {
        $issue = compact('path', 'code', 'severity', 'message');
        if (func_num_args() >= 5) {
            $issue['value'] = $value;
        }

        return $issue;
    }
}
