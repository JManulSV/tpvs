<?php

namespace App\Domain\AI\Exceptions;

use App\Domain\Shared\Exceptions\DomainRuleException;

final class AiProcessingRetryBlocked extends DomainRuleException
{
    public static function invalidState(): self
    {
        return new self('The AI processing retry is not allowed for this record state.', 'AI_PROCESSING_RETRY_BLOCKED', 'INVALID_STATE', 409);
    }
}
