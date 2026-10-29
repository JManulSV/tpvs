<?php

namespace Tests\Unit;

use App\Domain\Documents\SpanishDateFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpanishDateFormatterTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function headings(): array
    {
        return [
            'wednesday' => ['2026-07-08', 'MIÉRCOLES 08 DE JULIO DE 2026'],
            'saturday' => ['2026-09-19', 'SÁBADO 19 DE SEPTIEMBRE DE 2026'],
            'sunday' => ['2026-09-13', 'DOMINGO 13 DE SEPTIEMBRE DE 2026'],
            'monday' => ['2026-01-05', 'LUNES 05 DE ENERO DE 2026'],
            'tuesday' => ['2026-03-10', 'MARTES 10 DE MARZO DE 2026'],
            'thursday' => ['2026-12-31', 'JUEVES 31 DE DICIEMBRE DE 2026'],
            'friday' => ['2026-10-30', 'VIERNES 30 DE OCTUBRE DE 2026'],
        ];
    }

    #[DataProvider('headings')]
    public function test_heading_uses_spanish_weekday_and_month_with_accents(string $date, string $expected): void
    {
        $this->assertSame($expected, (new SpanishDateFormatter)->heading(CarbonImmutable::parse($date)));
    }

    public function test_heading_covers_every_month_name(): void
    {
        $formatter = new SpanishDateFormatter;
        $months = [];

        foreach (range(1, 12) as $month) {
            $months[] = explode(' ', $formatter->heading(CarbonImmutable::create(2026, $month, 10)))[3];
        }

        $this->assertSame([
            'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
            'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE',
        ], $months);
    }

    public function test_week_range_within_one_year_shows_the_year_once(): void
    {
        $range = (new SpanishDateFormatter)->weekRange(
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-13'),
        );

        $this->assertSame('07 DE SEPTIEMBRE AL 13 DE SEPTIEMBRE DE 2026', $range);
    }

    public function test_week_range_across_months_in_one_year(): void
    {
        $range = (new SpanishDateFormatter)->weekRange(
            CarbonImmutable::parse('2026-09-28'),
            CarbonImmutable::parse('2026-10-04'),
        );

        $this->assertSame('28 DE SEPTIEMBRE AL 04 DE OCTUBRE DE 2026', $range);
    }

    public function test_week_range_across_years_shows_both_years(): void
    {
        $range = (new SpanishDateFormatter)->weekRange(
            CarbonImmutable::parse('2025-12-29'),
            CarbonImmutable::parse('2026-01-04'),
        );

        $this->assertSame('29 DE DICIEMBRE DE 2025 AL 04 DE ENERO DE 2026', $range);
    }

    public function test_output_does_not_depend_on_the_carbon_locale(): void
    {
        CarbonImmutable::setLocale('en');

        $this->assertSame(
            'MIÉRCOLES 08 DE JULIO DE 2026',
            (new SpanishDateFormatter)->heading(CarbonImmutable::parse('2026-07-08')),
        );
    }
}
