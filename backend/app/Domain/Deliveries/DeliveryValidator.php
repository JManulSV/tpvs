<?php

namespace App\Domain\Deliveries;

use App\Domain\Documents\Exceptions\DocumentGenerationBlockedException;
use App\Domain\Shared\Money;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DeliveryValidator
{
    public const DOCUMENT_CASH_TOLERANCE_CENTS = 100;

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
        return $this->currentReturn($record)->cents;
    }

    public function currentReturnAmount(Record $record): string
    {
        return $this->currentReturn($record)->toDecimal();
    }

    /**
     * The document amount choices are the current whole peso, the same peso
     * plus fifty cents, and the next whole peso.
     *
     * @return array{string, string, string}
     */
    public function documentReturnOptions(string $officialReturnAmount): array
    {
        $officialReturn = $this->amount($officialReturnAmount);

        if ($officialReturn->isNegative()) {
            throw new InvalidArgumentException('The official return amount must not be negative.');
        }

        $wholePeso = $officialReturn->floorToWholePeso();

        return [
            $wholePeso->toDecimal(),
            $wholePeso->add(Money::fromCents(50))->toDecimal(),
            $wholePeso->add(Money::fromCents(100))->toDecimal(),
        ];
    }

    public function isDocumentReturnOption(string $officialReturnAmount, string $documentReturnAmount): bool
    {
        try {
            $canonicalDocumentAmount = $this->canonicalAmount($documentReturnAmount);
            if ($canonicalDocumentAmount === null) {
                return false;
            }

            return in_array(
                $canonicalDocumentAmount,
                $this->documentReturnOptions($officialReturnAmount),
                true,
            );
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function isStale(string $preparedReturnAmount, string $currentReturnAmount): bool
    {
        return $this->amount($preparedReturnAmount)->compare($this->amount($currentReturnAmount)) !== 0;
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
            static fn (array $left, array $right): int => Money::fromDecimal($right['value'])->compare(Money::fromDecimal($left['value'])),
        );

        return $canonical;
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     */
    public function cashTotalCents(array $denominations): int
    {
        return $this->cashTotalMoney($denominations)->cents;
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     */
    public function cashTotal(array $denominations): string
    {
        return $this->cashTotalMoney($denominations)->toDecimal();
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     * @return array{expected_amount: string, cash_amount: string, difference: string}|null
     */
    public function cashMismatch(string $expectedAmount, array $denominations): ?array
    {
        $expected = $this->amount($expectedAmount);
        $cash = $this->cashTotalMoney($denominations);

        if ($expected->compare($cash) === 0) {
            return null;
        }

        return [
            'expected_amount' => $expected->toDecimal(),
            'cash_amount' => $cash->toDecimal(),
            'difference' => $cash->subtract($expected)->toDecimal(),
        ];
    }

    /**
     * At preparation time, allow up to one peso of missing cash and any excess.
     * Preserve the existing rule that an empty normalized breakdown is valid
     * only for a zero return.
     *
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     * @return array{expected_amount: string, cash_amount: string, difference: string}|null
     */
    public function preparationCashShortfall(string $expectedAmount, array $denominations): ?array
    {
        $expected = $this->amount($expectedAmount);
        $cash = $this->cashTotalMoney($denominations);
        $difference = $cash->subtract($expected);

        if ($difference->cents >= -self::DOCUMENT_CASH_TOLERANCE_CENTS
            && ! ($expected->cents > 0 && $this->canonicalizeDenominations($denominations) === [])) {
            return null;
        }

        return [
            'expected_amount' => $expected->toDecimal(),
            'cash_amount' => $cash->toDecimal(),
            'difference' => $difference->toDecimal(),
        ];
    }

    /**
     * Compare gross cash with the selected document amount and determine
     * whether change is required or may be printed.
     *
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     * @return array{expected_amount: string, cash_amount: string, difference: string, change_amount: ?string, reason: ?string}
     */
    public function documentCashReconciliation(
        string $documentReturnAmount,
        array $denominations,
        bool $customerWillGiveChange,
    ): array {
        $expected = $this->amount($documentReturnAmount);
        $cash = $this->cashTotalMoney($denominations);
        $difference = $cash->subtract($expected);
        $reason = null;
        $changeAmount = null;

        if ($difference->cents < -self::DOCUMENT_CASH_TOLERANCE_CENTS) {
            $reason = DocumentGenerationBlockedException::CASH_MISMATCH;
        } elseif ($difference->cents > self::DOCUMENT_CASH_TOLERANCE_CENTS) {
            if ($customerWillGiveChange) {
                $changeAmount = $difference->toDecimal();
            } else {
                $reason = DocumentGenerationBlockedException::CHANGE_REQUIRED;
            }
        }

        return [
            'expected_amount' => $expected->toDecimal(),
            'cash_amount' => $cash->toDecimal(),
            'difference' => $difference->toDecimal(),
            'change_amount' => $changeAmount,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     * @return array{expected_amount: string, cash_amount: string, difference: string, change_amount: ?string}
     */
    public function validateDocumentCashReconciliation(
        string $officialReturnAmount,
        string $documentReturnAmount,
        array $denominations,
        bool $customerWillGiveChange,
    ): array {
        if (! $this->isDocumentReturnOption($officialReturnAmount, $documentReturnAmount)) {
            throw ValidationException::withMessages([
                'document_return_amount' => ['The document return amount is not an allowed adjustment for the current return.'],
            ]);
        }

        $reconciliation = $this->documentCashReconciliation(
            $documentReturnAmount,
            $denominations,
            $customerWillGiveChange,
        );

        if ($reconciliation['reason'] !== null) {
            throw new DocumentGenerationBlockedException($reconciliation['reason'], [
                'expected_amount' => $reconciliation['expected_amount'],
                'cash_amount' => $reconciliation['cash_amount'],
                'difference' => $reconciliation['difference'],
            ]);
        }

        return [
            'expected_amount' => $reconciliation['expected_amount'],
            'cash_amount' => $reconciliation['cash_amount'],
            'difference' => $reconciliation['difference'],
            'change_amount' => $reconciliation['change_amount'],
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

        return Money::fromDecimal($value)->toDecimal();
    }

    public function canonicalQuantity(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) (int) $value;
    }

    private function currentReturn(Record $record): Money
    {
        return $this->amount($record->details()->sum('return_amount'));
    }

    private function amount(mixed $amount): Money
    {
        return Money::fromDecimal($this->canonicalAmount($amount));
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     */
    private function cashTotalMoney(array $denominations): Money
    {
        $total = Money::zero();

        foreach ($this->canonicalizeDenominations($denominations) as $denomination) {
            $total = $total->add(Money::fromDecimal($denomination['value'])->multiply($denomination['quantity']));
        }

        return $total;
    }
}
