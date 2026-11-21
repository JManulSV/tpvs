<?php

namespace App\Domain\Documents\Exceptions;

use App\Domain\Shared\Exceptions\DomainRuleException;

final class DocumentGenerationBlockedException extends DomainRuleException
{
    public const INVALID_RECORD_STATE = 'INVALID_RECORD_STATE';

    public const MISSING_IMAGE = 'MISSING_IMAGE';

    public const STALE_DELIVERY = 'STALE_DELIVERY';

    public const CASH_MISMATCH = 'CASH_MISMATCH';

    public const CHANGE_REQUIRED = 'CHANGE_REQUIRED';

    public const IMAGE_UNAVAILABLE = 'IMAGE_UNAVAILABLE';

    /**
     * @param  array<string, string>  $details  extra safe fields exposed next to the reason
     */
    public function __construct(string $reason, array $details = [])
    {
        parent::__construct('The delivery document cannot be generated.', 'DOCUMENT_GENERATION_BLOCKED', $reason, 409, $details);
    }
}
