<?php

namespace App\Domain\Shared\History;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Canonical string forms for history snapshots, so equivalent inputs
 * ("  Ana ", 7 and "7", "1000" and "1000.00") never show up as changes.
 */
final class CanonicalValue
{
    /** Trimmed text; blank becomes null. */
    public static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public static function integer(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) (int) $value;
    }

    /** "Y-m-d" */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return CarbonImmutable::parse((string) $value)->toDateString();
    }

    /** Two decimals, truncated, without leading zeros: "007.5" => "7.50". */
    public static function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }
}
