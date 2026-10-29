<?php

namespace App\Domain\Documents;

use InvalidArgumentException;

class MoneyToWordsService
{
    private const MAX_PESOS = 9_999_999_999;

    private const UNITS = [
        'CERO', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIÚN', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];

    private const TENS = [
        3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA',
    ];

    private const HUNDREDS = [
        1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS',
        6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS',
    ];

    /**
     * Convert a decimal amount string (for example "920.00") to the fiscal
     * format "NOVECIENTOS VEINTE PESOS 00/100 M.N.", using integer arithmetic.
     */
    public function convert(string $amount): string
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts) !== 1) {
            throw new InvalidArgumentException('The amount must be a non-negative decimal with at most two decimals.');
        }

        $pesos = (int) $parts[1];

        if (strlen(ltrim($parts[1], '0')) > 10 || $pesos > self::MAX_PESOS) {
            throw new InvalidArgumentException('The amount exceeds the supported range.');
        }

        $cents = str_pad($parts[2] ?? '', 2, '0');

        return $this->pesosInWords($pesos)." {$cents}/100 M.N.";
    }

    private function pesosInWords(int $pesos): string
    {
        if ($pesos === 1) {
            return 'UN PESO';
        }

        $millions = intdiv($pesos, 1_000_000);
        $rest = $pesos % 1_000_000;
        $words = [];

        if ($millions === 1) {
            $words[] = 'UN MILLÓN';
        } elseif ($millions > 1) {
            $words[] = $this->integerInWords($millions).' MILLONES';
        }

        if ($rest > 0) {
            $words[] = $this->integerInWords($rest);
        }

        if ($pesos === 0) {
            $words[] = self::UNITS[0];
        }

        return implode(' ', $words).($rest === 0 && $millions > 0 ? ' DE PESOS' : ' PESOS');
    }

    /** Words for 1..999,999,999 (up to 9,999 for the millions group), with "UN" apocopated. */
    private function integerInWords(int $number): string
    {
        $thousands = intdiv($number, 1000);
        $rest = $number % 1000;
        $words = [];

        if ($thousands === 1) {
            $words[] = 'MIL';
        } elseif ($thousands > 1) {
            $words[] = $this->belowThousand($thousands).' MIL';
        }

        if ($rest > 0) {
            $words[] = $this->belowThousand($rest);
        }

        return implode(' ', $words);
    }

    private function belowThousand(int $number): string
    {
        if ($number >= 1000) {
            return $this->integerInWords($number);
        }

        if ($number === 100) {
            return 'CIEN';
        }

        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $words = [];

        if ($hundreds > 0) {
            $words[] = self::HUNDREDS[$hundreds];
        }

        if ($rest > 0) {
            $words[] = $rest < 30
                ? self::UNITS[$rest]
                : self::TENS[intdiv($rest, 10)].($rest % 10 > 0 ? ' Y '.self::UNITS[$rest % 10] : '');
        }

        return implode(' ', $words);
    }
}
