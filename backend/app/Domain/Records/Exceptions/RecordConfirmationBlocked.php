<?php

namespace App\Domain\Records\Exceptions;

use App\Domain\Shared\Exceptions\DomainRuleException;

final class RecordConfirmationBlocked extends DomainRuleException
{
    private const CODE = 'RECORD_CONFIRMATION_BLOCKED';

    public static function invalidState(): self
    {
        return new self('The record cannot be confirmed in its current state.', self::CODE, 'INVALID_STATE', 409);
    }

    public static function businessValidationFailed(): self
    {
        return new self('The record cannot be confirmed until its business data is valid.', self::CODE, 'BUSINESS_VALIDATION_FAILED', 409);
    }

    public static function imageBusinessValidationFailed(): self
    {
        return new self('The image record cannot be confirmed until its business data is valid.', self::CODE, 'BUSINESS_VALIDATION_FAILED', 409);
    }
}
