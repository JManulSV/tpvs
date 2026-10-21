<?php

namespace App\Domain\AI\DTO;

final readonly class AiExtractionValidationResult
{
    /**
     * @param  array<string, mixed>|null  $normalizedExtraction
     * @param  list<array{path: string, code: string, severity: string, message: string, value?: mixed}>  $issues
     */
    public function __construct(
        public bool $structurallyValid,
        public ?array $normalizedExtraction,
        public array $issues,
        public int $unconvertibleNumericCount,
    ) {}

    public function isUsable(): bool
    {
        return $this->structurallyValid && $this->unconvertibleNumericCount < 5;
    }
}
