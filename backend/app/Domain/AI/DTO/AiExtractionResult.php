<?php

namespace App\Domain\AI\DTO;

/**
 * The provider response and its decoded extraction. Raw provider content is backend-private.
 */
final readonly class AiExtractionResult
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $decodedResponse
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $promptVersion,
        public string $rawResponse,
        public string $responseText,
        public ?array $decodedResponse,
    ) {}
}
