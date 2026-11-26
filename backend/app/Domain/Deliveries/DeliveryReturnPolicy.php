<?php

namespace App\Domain\Deliveries;

use App\Domain\Shared\Money;
use App\Models\Record;
use InvalidArgumentException;

/** The official return of a record and the amounts a delivery document may print for its cash. */
class DeliveryReturnPolicy
{
    public function __construct(
        private readonly DeliveryInputNormalizer $normalizer = new DeliveryInputNormalizer,
    ) {}

    /** The record's official return, calculated once by RecordFinancials (SPEC 27). */
    public function currentReturnAmount(Record $record): string
    {
        return $this->normalizer->money($record->return_amount)->toDecimal();
    }

    /** A prepared delivery is stale when the record's return changed after preparation. */
    public function isStale(string $preparedReturnAmount, string $currentReturnAmount): bool
    {
        return $this->normalizer->money($preparedReturnAmount)->compare($this->normalizer->money($currentReturnAmount)) !== 0;
    }

    /**
     * The document amount choices, derived from the cash to deliver (SPEC 28):
     * the current whole peso, the same peso plus fifty cents, and the next
     * whole peso. Without cash the only valid document amount is 0.00.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function documentReturnOptions(string $cashAmount): array
    {
        $cash = $this->normalizer->money($cashAmount);

        if ($cash->isNegative()) {
            throw new InvalidArgumentException('The cash amount must not be negative.');
        }

        if ($cash->compare(Money::zero()) === 0) {
            return [Money::zero()->toDecimal()];
        }

        $wholePeso = $cash->floorToWholePeso();

        return [
            $wholePeso->toDecimal(),
            $wholePeso->add(Money::fromCents(50))->toDecimal(),
            $wholePeso->add(Money::fromCents(100))->toDecimal(),
        ];
    }

    public function isDocumentReturnOption(string $cashAmount, string $documentReturnAmount): bool
    {
        try {
            $canonicalDocumentAmount = $this->normalizer->canonicalAmount($documentReturnAmount);
            if ($canonicalDocumentAmount === null) {
                return false;
            }

            return in_array(
                $canonicalDocumentAmount,
                $this->documentReturnOptions($cashAmount),
                true,
            );
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
