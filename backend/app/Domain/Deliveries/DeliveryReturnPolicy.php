<?php

namespace App\Domain\Deliveries;

use App\Domain\Shared\Money;
use App\Models\Record;
use InvalidArgumentException;

/** The official return of a record and the amounts a delivery document may print. */
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
     * The document amount choices are the current whole peso, the same peso
     * plus fifty cents, and the next whole peso.
     *
     * @return array{string, string, string}
     *
     * @throws InvalidArgumentException
     */
    public function documentReturnOptions(string $officialReturnAmount): array
    {
        $officialReturn = $this->normalizer->money($officialReturnAmount);

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
            $canonicalDocumentAmount = $this->normalizer->canonicalAmount($documentReturnAmount);
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
}
