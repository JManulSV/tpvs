<?php

namespace App\Domain\Deliveries;

use App\Models\Record;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class DeliveryValidator
{
    public const DENOMINATIONS = [
        '1000.00',
        '500.00',
        '200.00',
        '100.00',
        '50.00',
        '20.00',
        '10.00',
        '5.00',
        '2.00',
        '1.00',
        '0.50',
    ];

    public function currentReturnCents(Record $record): int
    {
        return $this->toCents($this->canonicalAmount($record->details()->sum('return_amount')) ?? '0.00');
    }

    public function currentReturnAmount(Record $record): string
    {
        return $this->fromCents($this->currentReturnCents($record));
    }

    public function isStale(string $preparedReturnAmount, string $currentReturnAmount): bool
    {
        return $this->amountCents($preparedReturnAmount) !== $this->amountCents($currentReturnAmount);
    }

    public function canonicalDenomination(string $value): ?string
    {
        try {
            $amount = $this->canonicalAmount($value);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $amount !== null && in_array($amount, self::DENOMINATIONS, true) ? $amount : null;
    }

    public function isOfficialDenomination(string $value): bool
    {
        return $this->canonicalDenomination($value) !== null;
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     * @return list<array{value: string, quantity: int}>
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
            fn (array $left, array $right): int => $this->toCents($right['value']) <=> $this->toCents($left['value']),
        );

        return $canonical;
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     */
    public function cashTotalCents(array $denominations): int
    {
        $total = 0;

        foreach ($this->canonicalizeDenominations($denominations) as $denomination) {
            $total += $this->toCents($denomination['value']) * $denomination['quantity'];
        }

        return $total;
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     */
    public function cashTotal(array $denominations): string
    {
        return $this->fromCents($this->cashTotalCents($denominations));
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     * @return array{expected_amount: string, cash_amount: string, difference: string}|null
     */
    public function cashMismatch(string $expectedAmount, array $denominations): ?array
    {
        $expectedCents = $this->amountCents($expectedAmount);
        $cashCents = $this->cashTotalCents($denominations);

        if ($expectedCents === $cashCents) {
            return null;
        }

        return [
            'expected_amount' => $this->fromCents($expectedCents),
            'cash_amount' => $this->fromCents($cashCents),
            'difference' => $this->fromCents($cashCents - $expectedCents),
        ];
    }

    public function canonicalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return CarbonImmutable::parse((string) $value)->toDateString();
    }

    public function canonicalName(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function canonicalAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('The amount must be a decimal with at most two decimals.');
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $canonical = $whole.'.'.$fraction;

        return $negative && $canonical !== '0.00' ? '-'.$canonical : $canonical;
    }

    public function canonicalQuantity(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) (int) $value;
    }

    private function amountCents(string $amount): int
    {
        return $this->toCents($this->canonicalAmount($amount) ?? '0.00');
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $amount = abs($amount);

        return $sign.intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
