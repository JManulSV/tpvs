<?php

namespace App\Domain\Deliveries;

use App\Domain\Shared\History\CanonicalValue;
use App\Domain\Shared\Money;
use InvalidArgumentException;

/** Canonical forms of delivery input: dates, names, amounts and cash denominations. */
class DeliveryInputNormalizer
{
    public function canonicalDate(mixed $value): ?string
    {
        return CanonicalValue::date($value);
    }

    public function canonicalName(mixed $value): ?string
    {
        return CanonicalValue::string($value);
    }

    /**
     * A decimal with at most two places, e.g. "1000" => "1000.00", "-0" => "0.00".
     * Unlike CanonicalValue::decimal() it rejects anything else.
     *
     * @throws InvalidArgumentException
     */
    public function canonicalAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('The amount must be a decimal with at most two decimals.');
        }

        return Money::fromDecimal($value)->toDecimal();
    }

    public function canonicalQuantity(mixed $value): ?string
    {
        return CanonicalValue::integer($value);
    }

    /** The canonical official denomination, or null when the value is not one. */
    public function canonicalDenomination(string $value): ?string
    {
        try {
            $amount = $this->canonicalAmount($value);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $amount !== null && in_array($amount, CashBreakdown::DENOMINATIONS, true) ? $amount : null;
    }

    /**
     * Drop zero quantities, canonicalize values and sort from the largest denomination down.
     *
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     * @return list<array{value: string, quantity: int}>
     *
     * @throws InvalidArgumentException
     */
    public function canonicalizeDenominations(array $denominations): array
    {
        $canonical = [];

        foreach ($denominations as $denomination) {
            $quantity = (int) ($denomination['quantity'] ?? 0);

            if ($quantity === 0) {
                continue;
            }

            if ($quantity < 0) {
                throw new InvalidArgumentException('Denomination quantities must not be negative.');
            }

            $value = $this->canonicalDenomination((string) ($denomination['value'] ?? ''));
            if ($value === null) {
                throw new InvalidArgumentException('The denomination is not part of the official list.');
            }

            $canonical[] = ['value' => $value, 'quantity' => $quantity];
        }

        usort(
            $canonical,
            static fn (array $left, array $right): int => Money::fromDecimal($right['value'])->compare(Money::fromDecimal($left['value'])),
        );

        return $canonical;
    }

    /** A canonical amount as Money; null and '' are zero. */
    public function money(mixed $amount): Money
    {
        return Money::fromDecimal($this->canonicalAmount($amount));
    }
}
