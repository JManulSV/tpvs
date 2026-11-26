<?php

namespace App\Domain\Deliveries;

use App\Domain\Shared\Money;

/**
 * How a Delivery splits the official return between transfer and cash (SPEC 28).
 *
 * The stored input is the cash the customer asks for. The transfer is the exact
 * remainder of the official return, and only the cash pays the extra commission,
 * applied with Money::applyPercentage (half-up to the cent):
 *
 *   transfer_amount = return − requested_cash
 *   cash_commission = requested_cash × percentage
 *   cash_amount     = requested_cash − cash_commission
 *
 * Inputs are expected to be already validated: amounts ≥ 0 with two decimals,
 * percentage 0–100, and requested cash ≤ return.
 */
final readonly class DeliverySplit
{
    private function __construct(
        public string $returnAmount,
        public string $requestedCashAmount,
        public string $cashCommissionPercentage,
        public string $transferAmount,
        public string $cashCommission,
        public string $cashAmount,
    ) {}

    public static function calculate(string $returnAmount, string $requestedCashAmount, string $cashCommissionPercentage): self
    {
        $return = Money::fromDecimal($returnAmount);
        $requestedCash = Money::fromDecimal($requestedCashAmount);
        $commission = $requestedCash->applyPercentage($cashCommissionPercentage);

        return new self(
            $return->toDecimal(),
            $requestedCash->toDecimal(),
            // Percentages share the two-decimal fixed-point format, so Money canonicalizes them too.
            Money::fromDecimal($cashCommissionPercentage)->toDecimal(),
            $return->subtract($requestedCash)->toDecimal(),
            $commission->toDecimal(),
            $requestedCash->subtract($commission)->toDecimal(),
        );
    }

    /**
     * The Delivery columns this split persists. transfer_amount is derived on read.
     *
     * @return array{requested_cash_amount: string, cash_commission_percentage: string, cash_commission: string, cash_amount: string}
     */
    public function attributes(): array
    {
        return [
            'requested_cash_amount' => $this->requestedCashAmount,
            'cash_commission_percentage' => $this->cashCommissionPercentage,
            'cash_commission' => $this->cashCommission,
            'cash_amount' => $this->cashAmount,
        ];
    }

    public function hasCash(): bool
    {
        return Money::fromDecimal($this->cashAmount)->compare(Money::zero()) > 0;
    }
}
