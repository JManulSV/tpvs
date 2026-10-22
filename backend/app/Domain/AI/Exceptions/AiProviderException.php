<?php

namespace App\Domain\AI\Exceptions;

use RuntimeException;

final class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $safeMessage,
        public readonly bool $transient,
        public readonly ?string $rawResponse = null,
        public readonly ?string $promptVersion = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($safeMessage);
    }
}
