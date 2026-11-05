<?php

namespace App\Domain\Documents\Exceptions;

use RuntimeException;

final class DocumentGenerationBlockedException extends RuntimeException
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
    public function __construct(
        public readonly string $reason,
        public readonly array $details = [],
    ) {
        parent::__construct('The delivery document cannot be generated.');
    }

    /** @return array{reason: string}&array<string, string> */
    public function responseDetails(): array
    {
        return ['reason' => $this->reason] + $this->details;
    }
}
