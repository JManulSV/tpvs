<?php

namespace App\Domain\AI;

use App\Domain\AI\DTO\AiExtractionResult;
use App\Domain\AI\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OllamaAIService implements AIService
{
    private const PROMPT_VERSION = 'v5';

    public function extract(string $imagePath): AiExtractionResult
    {
        $url = rtrim((string) config('ai.ollama.url'), '/');
        $model = (string) config('ai.ollama.model');
        $timeout = (int) config('ai.ollama.timeout');

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new AiProviderException(
                'AI_PROVIDER_NOT_CONFIGURED',
                'The AI provider is not configured.',
                false,
                promptVersion: self::PROMPT_VERSION,
            );
        }

        if ($model === '' || $timeout < 1) {
            throw new AiProviderException(
                'AI_PROVIDER_NOT_CONFIGURED',
                'The AI provider is not configured.',
                false,
                promptVersion: self::PROMPT_VERSION,
            );
        }

        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw new AiProviderException(
                'AI_IMAGE_UNAVAILABLE',
                'The image is unavailable for processing.',
                false,
                promptVersion: self::PROMPT_VERSION,
            );
        }

        $image = file_get_contents($imagePath);
        if (! is_string($image) || $image === '') {
            throw new AiProviderException(
                'AI_IMAGE_UNAVAILABLE',
                'The image is unavailable for processing.',
                false,
                promptVersion: self::PROMPT_VERSION,
            );
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(min($timeout, 10))
                ->timeout($timeout)
                ->post($url.'/api/chat', [
                    'model' => $model,
                    'stream' => false,
                    'format' => $this->responseSchema(),
                    'options' => [
                        'temperature' => 0,
                    ],
                    'messages' => [[
                        'role' => 'user',
                        'content' => $this->prompt(),
                        'images' => [base64_encode($image)],
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new AiProviderException(
                'AI_PROVIDER_CONNECTION_FAILED',
                'The AI provider could not be reached or timed out.',
                true,
                promptVersion: self::PROMPT_VERSION,
            );
        }

        $rawResponse = $response->body();

        if (! $response->successful()) {
            $status = $response->status();
            $transient = $status >= 500;

            throw new AiProviderException(
                $transient ? 'AI_PROVIDER_SERVER_ERROR' : 'AI_PROVIDER_REQUEST_REJECTED',
                $transient
                    ? 'The AI provider returned a server error.'
                    : 'The AI provider rejected the extraction request.',
                $transient,
                rawResponse: $rawResponse,
                promptVersion: self::PROMPT_VERSION,
                httpStatus: $status,
            );
        }

        $providerResponse = json_decode($rawResponse, true);
        $responseText = is_array($providerResponse)
            ? data_get($providerResponse, 'message.content')
            : null;

        if (! is_string($responseText)) {
            throw new AiProviderException(
                'AI_PROVIDER_INVALID_RESPONSE',
                'The AI provider returned an unreadable response.',
                false,
                rawResponse: $rawResponse,
                promptVersion: self::PROMPT_VERSION,
                httpStatus: $response->status(),
            );
        }

        $decodedResponse = json_decode($responseText, true);

        return new AiExtractionResult(
            provider: 'ollama',
            model: $model,
            promptVersion: self::PROMPT_VERSION,
            rawResponse: $rawResponse,
            responseText: $responseText,
            decodedResponse: is_array($decodedResponse) ? $decodedResponse : null,
        );
    }

    private function prompt(): string
    {
        $prompt = file_get_contents(resource_path('ai/prompt-v5.txt'));

        if (! is_string($prompt) || $prompt === '') {
            throw new AiProviderException(
                'AI_PROMPT_UNAVAILABLE',
                'The AI extraction prompt is unavailable.',
                false,
                promptVersion: self::PROMPT_VERSION,
            );
        }

        return $prompt;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        $field = static fn (array $valueTypes): array => [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => $valueTypes],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['value', 'confidence'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'record' => [
                    'type' => 'object',
                    'properties' => [
                        'company_name' => $field(['string', 'null']),
                        'year' => $field(['integer', 'null']),
                        'week' => $field(['integer', 'null']),
                        'week_start_date' => $field(['string', 'null']),
                        'week_end_date' => $field(['string', 'null']),
                    ],
                    'required' => ['company_name', 'year', 'week', 'week_start_date', 'week_end_date'],
                    'additionalProperties' => false,
                ],
                'details' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'affiliation' => $field(['string', 'null']),
                            'branch' => $field(['string', 'null']),
                            'income' => $field(['string', 'null']),
                            'commission_percentage' => $field(['string', 'null']),
                            'source_commission' => $field(['string', 'null']),
                        ],
                        'required' => ['affiliation', 'branch', 'income', 'commission_percentage', 'source_commission'],
                        'additionalProperties' => false,
                    ],
                ],
                'source_totals' => [
                    'type' => 'object',
                    'properties' => [
                        'income' => $field(['string', 'null']),
                        'commission' => $field(['string', 'null']),
                        'return_amount' => $field(['string', 'null']),
                    ],
                    'required' => ['income', 'commission', 'return_amount'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['record', 'details', 'source_totals'],
            'additionalProperties' => false,
        ];
    }
}
