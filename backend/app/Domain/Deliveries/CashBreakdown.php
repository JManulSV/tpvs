<?php

namespace App\Domain\Deliveries;

use App\Domain\Documents\Exceptions\DocumentGenerationBlockedException;
use App\Domain\Shared\Money;
use Illuminate\Validation\ValidationException;

/** Cash denominations: their total and how it reconciles with the amount to deliver. */
class CashBreakdown
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

    public function __construct(
        private readonly DeliveryInputNormalizer $normalizer = new DeliveryInputNormalizer,
        private readonly DeliveryReturnPolicy $returnPolicy = new DeliveryReturnPolicy,
    ) {}

    public function isOfficialDenomination(string $value): bool
    {
        return $this->normalizer->canonicalDenomination($value) !== null;
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     */
    public function cashTotal(array $denominations): string
    {
        return $this->total($denominations)->toDecimal();
    }

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     * @return array{expected_amount: string, cash_amount: string, difference: string}|null
     */
    public function cashMismatch(string $expectedAmount, array $denominations): ?array
    {
        $expected = $this->normalizer->money($expectedAmount);
        $cash = $this->total($denominations);

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
        $expected = $this->normalizer->money($expectedAmount);
        $cash = $this->total($denominations);
        $difference = $cash->subtract($expected);

        if ($difference->cents >= -self::DOCUMENT_CASH_TOLERANCE_CENTS
            && ! ($expected->cents > 0 && $this->normalizer->canonicalizeDenominations($denominations) === [])) {
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
        $expected = $this->normalizer->money($documentReturnAmount);
        $cash = $this->total($denominations);
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
     * @param  string  $cashAmount  the Delivery's cash to deliver, the base of the document options (SPEC 28)
     * @return array{expected_amount: string, cash_amount: string, difference: string, change_amount: ?string}
     *
     * @throws ValidationException when the document amount is not an allowed option
     * @throws DocumentGenerationBlockedException when the cash does not reconcile
     */
    public function validateDocumentCashReconciliation(
        string $cashAmount,
        string $documentReturnAmount,
        array $denominations,
        bool $customerWillGiveChange,
    ): array {
        if (! $this->returnPolicy->isDocumentReturnOption($cashAmount, $documentReturnAmount)) {
            throw ValidationException::withMessages([
                'document_return_amount' => ['The document return amount is not an allowed adjustment for the cash to deliver.'],
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

    /**
     * @param  array<int, array{value?: mixed, quantity?: mixed}>  $denominations
     */
    private function total(array $denominations): Money
    {
        $total = Money::zero();

        foreach ($this->normalizer->canonicalizeDenominations($denominations) as $denomination) {
            $total = $total->add(Money::fromDecimal($denomination['value'])->multiply($denomination['quantity']));
        }

        return $total;
    }
}
