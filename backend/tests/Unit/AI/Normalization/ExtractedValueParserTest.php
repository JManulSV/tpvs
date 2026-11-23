<?php

namespace Tests\Unit\AI\Normalization;

use App\Domain\AI\Normalization\ExtractedValueParser;
use PHPUnit\Framework\TestCase;

class ExtractedValueParserTest extends TestCase
{
    private ExtractedValueParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ExtractedValueParser;
    }

    public function test_it_parses_plain_grouped_and_currency_amounts(): void
    {
        $this->assertSame('1234.00', $this->parser->parseNumber('1234', false));
        $this->assertSame('1234.50', $this->parser->parseNumber('1234.5', false));
        $this->assertSame('1234.50', $this->parser->parseNumber('1,234.50', false));
        $this->assertSame('1234.50', $this->parser->parseNumber('1.234,50', false));
        $this->assertSame('99.00', $this->parser->parseNumber(' $ 99 ', false));
        $this->assertSame('7.00', $this->parser->parseNumber('007', false));
    }

    public function test_it_rejects_ambiguous_or_misplaced_formats(): void
    {
        $this->assertNull($this->parser->parseNumber('1,234', false), 'a lone comma group is ambiguous');
        $this->assertNull($this->parser->parseNumber('12.345', false), 'three decimals are ambiguous');
        $this->assertNull($this->parser->parseNumber('12%', false), 'amounts cannot carry a percent sign');
        $this->assertNull($this->parser->parseNumber('$12', true), 'percentages cannot carry a currency sign');
        $this->assertNull($this->parser->parseNumber('-5', false));
        $this->assertNull($this->parser->parseNumber('', false));
    }

    public function test_percentages_accept_a_trailing_percent_sign(): void
    {
        $this->assertSame('12.50', $this->parser->parseNumber('12.5%', true));
        $this->assertSame('10.00', $this->parser->parseNumber('10', true));
    }

    public function test_numeric_candidates_and_decimal_formatting(): void
    {
        $this->assertTrue($this->parser->isNumericCandidate(' 1,234.567 '));
        $this->assertTrue($this->parser->isNumericCandidate('-$5%'));
        $this->assertFalse($this->parser->isNumericCandidate('12 pesos'));

        $this->assertSame('7.50', $this->parser->formatDecimal('007', '5'));
        $this->assertSame('0.05', $this->parser->formatDecimal('000', '05'));
    }

    public function test_normalize_number_counts_only_numeric_looking_failures(): void
    {
        $issues = [];
        $count = 0;

        $this->assertSame('10.00', $this->parser->normalizeNumber('10', false, 'p', $issues, $count));
        $this->assertNull($this->parser->normalizeNumber(null, false, 'p', $issues, $count));
        $this->assertNull($this->parser->normalizeNumber('12.345', false, 'details.0.income', $issues, $count));
        $this->assertNull($this->parser->normalizeNumber('doce', false, 'details.1.income', $issues, $count));

        $this->assertSame(1, $count);
        $this->assertSame(['UNCONVERTIBLE_NUMERIC', 'INVALID_VALUE_FORMAT'], array_column($issues, 'code'));
        $this->assertSame('12.345', $issues[0]['value']);
    }

    public function test_normalize_integer_checks_year_and_week_ranges(): void
    {
        $issues = [];

        $this->assertSame(2026, $this->parser->normalizeInteger(2026, 'year', 'record.year', $issues));
        $this->assertSame(53, $this->parser->normalizeInteger(53, 'week', 'record.week', $issues));
        $this->assertNull($this->parser->normalizeInteger(54, 'week', 'record.week', $issues));
        $this->assertNull($this->parser->normalizeInteger(0, 'year', 'record.year', $issues));

        $this->assertSame(['INVALID_WEEK', 'INVALID_YEAR'], array_column($issues, 'code'));
    }

    public function test_normalize_date_requires_a_real_iso_date(): void
    {
        $issues = [];

        $this->assertSame('2026-02-28', $this->parser->normalizeDate('2026-02-28', 'record.week_start_date', $issues));
        $this->assertNull($this->parser->normalizeDate('28/02/2026', 'record.week_start_date', $issues));
        $this->assertNull($this->parser->normalizeDate('2026-02-30', 'record.week_end_date', $issues));

        $this->assertSame(['INVALID_DATE_FORMAT', 'INVALID_DATE'], array_column($issues, 'code'));
    }
}
