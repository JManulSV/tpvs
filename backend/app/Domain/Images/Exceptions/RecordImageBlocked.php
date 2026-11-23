<?php

namespace App\Domain\Images\Exceptions;

use App\Domain\Shared\Exceptions\DomainRuleException;

final class RecordImageBlocked extends DomainRuleException
{
    public static function uploadForCanceledRecord(): self
    {
        return new self('The image cannot be changed for a canceled record.', 'IMAGE_UPLOAD_BLOCKED', 'RECORD_CANCELED', 409);
    }

    public static function replacementAfterAiProcessing(): self
    {
        return new self('The image cannot be replaced after AI processing has started.', 'IMAGE_REPLACEMENT_BLOCKED', 'AI_PROCESSING_EXISTS', 409);
    }

    public static function accessForCanceledRecord(): self
    {
        return new self('The image is unavailable for a canceled record.', 'IMAGE_ACCESS_BLOCKED', 'RECORD_CANCELED', 409);
    }
}
