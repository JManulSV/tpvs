<?php

namespace App\Domain\Documents;

use Carbon\CarbonInterface;

class SpanishDateFormatter
{
    private const WEEKDAYS = [
        0 => 'DOMINGO', 1 => 'LUNES', 2 => 'MARTES', 3 => 'MIÉRCOLES', 4 => 'JUEVES', 5 => 'VIERNES', 6 => 'SÁBADO',
    ];

    private const MONTHS = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO',
        7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    /** Example: "MIÉRCOLES 08 DE JULIO DE 2026". */
    public function heading(CarbonInterface $date): string
    {
        return self::WEEKDAYS[$date->dayOfWeek].' '.$this->longDate($date);
    }

    /**
     * Example: "07 DE SEPTIEMBRE AL 13 DE SEPTIEMBRE DE 2026". The start year
     * is included only when it differs from the end year.
     */
    public function weekRange(CarbonInterface $start, CarbonInterface $end): string
    {
        $startText = $start->year === $end->year
            ? $this->dayAndMonth($start)
            : $this->longDate($start);

        return $startText.' AL '.$this->longDate($end);
    }

    private function longDate(CarbonInterface $date): string
    {
        return $this->dayAndMonth($date).' DE '.$date->year;
    }

    private function dayAndMonth(CarbonInterface $date): string
    {
        return $date->format('d').' DE '.self::MONTHS[$date->month];
    }
}
