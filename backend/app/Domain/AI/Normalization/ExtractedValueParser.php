<?php

namespace App\Domain\AI\Normalization;

use App\Domain\AI\DTO\ExtractionIssue;
use DateTimeImmutable;

/**
 * Turn the raw values a provider read from an image into canonical ones: integers
 * in range, real ISO dates and two-decimal number strings. A value that cannot be
 * normalized becomes null and adds a review issue.
 */
class ExtractedValueParser
{
    private const NUMERIC_CANDIDATE_PATTERN = '/^[\d.,\s$€£¥₹%+\-]+$/uD';

    /**
     * @param  'year'|'week'  $field
     * @param  list<array<string, mixed>>  $issues
     */
    public function normalizeInteger(mixed $value, string $field, string $path, array &$issues): ?int
    {
        if ($value === null) {
            return null;
        }

        $valid = $field === 'year' ? $value >= 1 && $value <= 9999 : $value >= 1 && $value <= 53;
        if (! $valid) {
            $issues[] = ExtractionIssue::review($path, $field === 'year' ? 'INVALID_YEAR' : 'INVALID_WEEK', 'The numeric value is outside the supported range.')->withValue($value)->toArray();

            return null;
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $issues */
    public function normalizeDate(mixed $value, string $path, array &$issues): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $issues[] = ExtractionIssue::review($path, 'INVALID_DATE_FORMAT', 'Dates must use ISO YYYY-MM-DD format.')->withValue($value)->toArray();

            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            $issues[] = ExtractionIssue::review($path, 'INVALID_DATE', 'The date is not a real calendar date.')->withValue($value)->toArray();

            return null;
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  int  $unconvertibleCount  incremented for each value that looks numeric but is ambiguous
     */
    public function normalizeNumber(mixed $value, bool $percentage, string $path, array &$issues, int &$unconvertibleCount): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = $this->parseNumber($value, $percentage);
        if ($normalized !== null) {
            return $normalized;
        }

        if (! $this->isNumericCandidate($value)) {
            $issues[] = ExtractionIssue::review($path, 'INVALID_VALUE_FORMAT', 'The value does not match the expected numeric format.')->withValue($value)->toArray();

            return null;
        }

        $unconvertibleCount++;
        $issues[] = ExtractionIssue::review($path, 'UNCONVERTIBLE_NUMERIC', 'The numeric value is ambiguous or malformed and was not normalized.')->withValue($value)->toArray();

        return null;
    }

    /**
     * "1,234.50", "1.234,50", "$ 99" or "12.5%" → "1234.50", "1234.50", "99.00", "12.50".
     * Returns null when the format is ambiguous or not numeric.
     */
    public function parseNumber(string $input, bool $percentage): ?string
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

    /** Whether the text is made only of digits, separators, currency and sign characters. */
    public function isNumericCandidate(string $input): bool
    {
        return preg_match(self::NUMERIC_CANDIDATE_PATTERN, trim($input)) === 1;
    }

    /** ("007", "5") → "7.50" */
    public function formatDecimal(string $whole, string $fraction): string
    {
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
