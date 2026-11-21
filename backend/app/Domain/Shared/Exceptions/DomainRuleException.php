<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * A business rule blocked the request. Rendered once in bootstrap/app.php as
 * {"message", "code", "details": {"reason", ...details}} with $status.
 */
abstract class DomainRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  extra safe fields exposed next to the reason
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly string $reason,
        public readonly int $status,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @return array{reason: string}&array<string, mixed> */
    public function responseDetails(): array
    {
        return ['reason' => $this->reason] + $this->details;
    }

    /** @return array{message: string, code: string, details: array<string, mixed>} */
    public function toResponseBody(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'details' => $this->responseDetails(),
        ];
    }
}
