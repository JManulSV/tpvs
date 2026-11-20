<?php

namespace App\Domain\Shared;

/**
 * An immutable amount of money in integer cents.
 *
 * Parsing truncates to two decimals (it never rounds) and no method uses floats.
 * Display formatting ("$1,234.50", Excel cells) belongs to Domain/Documents.
 */
final readonly class Money
{
    private function __construct(public int $cents) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * Parse a decimal string such as "1234.5", "-0.50" or "100". Null and ''
     * are zero. Fractions beyond two decimals are truncated, not rounded.
     */
    public static function fromDecimal(string|int|null $amount): self
    {
        if ($amount === null || $amount === '') {
            return self::zero();
        }

        return new self(self::hundredths((string) $amount));
    }

    /** "1234.50", "-0.50" */
    public function toDecimal(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $cents = abs($this->cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function multiply(int $times): self
    {
        return new self($this->cents * $times);
    }

    /** @return int -1, 0 or 1 */
    public function compare(self $other): int
    {
        return $this->cents <=> $other->cents;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    /**
     * Apply a decimal percentage ("12.5") with half-up rounding to the cent:
     * intdiv(cents × hundredths + 5000, 10000).
     */
    public function applyPercentage(string $percentage): self
    {
        return new self(intdiv(($this->cents * self::hundredths($percentage)) + 5000, 10000));
    }

    /** 12.75 → 12.00, -0.50 → -1.00 */
    public function floorToWholePeso(): self
    {
        $remainder = $this->cents % 100;

        return new self($this->cents - $remainder - ($remainder < 0 ? 100 : 0));
    }

    /** Parse a two-decimal fixed-point string into hundredths, truncating extra decimals. */
    private static function hundredths(string $value): int
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');

        $hundredths = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$hundredths : $hundredths;
    }
}
