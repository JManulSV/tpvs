<?php

namespace App\Domain\AI;

use App\Domain\AI\DTO\AiExtractionResult;

interface AIService
{
    public function extract(string $imagePath): AiExtractionResult;
}
