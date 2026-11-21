<?php

namespace App\Domain\Records\Exceptions;

use App\Domain\Shared\Exceptions\DomainRuleException;

final class RecordUpdateBlocked extends DomainRuleException
{
    public static function invalidState(): self
    {
        return new self('The record cannot be updated in its current state.', 'RECORD_UPDATE_BLOCKED', 'INVALID_STATE', 409);
    }

    public static function noChanges(): self
    {
        return new self('The record cannot be updated without changes.', 'RECORD_UPDATE_NO_CHANGES', 'NO_CHANGES', 422);
    }
}
