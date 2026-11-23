<?php

namespace App\Domain\Records\Exceptions;

use App\Domain\Shared\Exceptions\DomainRuleException;

final class RecordCancellationBlocked extends DomainRuleException
{
    public static function invalidState(): self
    {
        return new self('The record cannot be canceled in its current state.', 'RECORD_CANCELLATION_BLOCKED', 'INVALID_STATE', 409);
    }
}
