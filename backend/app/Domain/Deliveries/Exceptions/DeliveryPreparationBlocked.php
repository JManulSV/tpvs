<?php

namespace App\Domain\Deliveries\Exceptions;

use App\Domain\Shared\Exceptions\DomainRuleException;

final class DeliveryPreparationBlocked extends DomainRuleException
{
    private const CODE = 'DELIVERY_PREPARATION_BLOCKED';

    public static function invalidRecordState(): self
    {
        return new self('The delivery cannot be prepared in the current record state.', self::CODE, 'INVALID_RECORD_STATE', 409);
    }

    public static function cashMismatch(string $expectedAmount, string $cashAmount, string $difference): self
    {
        return new self(
            'The cash breakdown is more than one peso below the current return amount.',
            self::CODE,
            'CASH_MISMATCH',
            409,
            [
                'expected_amount' => $expectedAmount,
                'cash_amount' => $cashAmount,
                'difference' => $difference,
            ],
        );
    }
}
