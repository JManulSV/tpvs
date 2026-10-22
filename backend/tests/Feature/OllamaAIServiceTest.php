<?php

namespace Tests\Feature;

use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\OllamaAIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OllamaAIServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.ollama.url' => 'http://ollama.test:11434',
            'ai.ollama.model' => 'qwen2.5vl:3b',
            'ai.ollama.timeout' => 300,
        ]);
    }

    public function test_it_posts_image_prompt_and_schema_and_returns_raw_and_decoded_extraction(): void
    {
        $imagePath = $this->temporaryImage('image-bytes');
        $extraction = ['record' => ['company_name' => ['value' => 'Example']]];
        $content = json_encode($extraction, JSON_THROW_ON_ERROR);
        $rawProviderResponse = json_encode([
            'model' => 'qwen2.5vl:3b',
            'message' => ['content' => $content],
            'done' => true,
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'ollama.test:11434/api/chat' => Http::response($rawProviderResponse, 200),
        ]);

        $result = app(OllamaAIService::class)->extract($imagePath);

        $this->assertSame('ollama', $result->provider);
        $this->assertSame('qwen2.5vl:3b', $result->model);
        $this->assertSame('v4', $result->promptVersion);
        $this->assertSame($rawProviderResponse, $result->rawResponse);
        $this->assertSame($content, $result->responseText);
        $this->assertSame($extraction, $result->decodedResponse);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'http://ollama.test:11434/api/chat'
                && $body['model'] === 'qwen2.5vl:3b'
                && $body['stream'] === false
                && $body['messages'][0]['role'] === 'user'
                && str_contains($body['messages'][0]['content'], 'transcribe')
                && $body['messages'][0]['images'] === [base64_encode('image-bytes')]
                && $body['format']['required'] === ['record', 'details', 'source_totals'];
        });

        unlink($imagePath);
    }

    public function test_it_preserves_non_json_model_content_for_contract_validation(): void
    {
        $imagePath = $this->temporaryImage('image-bytes');
        $providerResponse = json_encode([
            'message' => ['content' => 'not json'],
        ], JSON_THROW_ON_ERROR);
        Http::fake([
            'ollama.test:11434/api/chat' => Http::response($providerResponse, 200),
        ]);

        $result = app(OllamaAIService::class)->extract($imagePath);

        $this->assertSame('not json', $result->responseText);
        $this->assertNull($result->decodedResponse);
        $this->assertSame($providerResponse, $result->rawResponse);

        unlink($imagePath);
    }

    public function test_it_classifies_server_errors_as_transient_and_sanitizes_provider_body(): void
    {
        $imagePath = $this->temporaryImage('image-bytes');
        Http::fake([
            'ollama.test:11434/api/chat' => Http::response('private provider diagnostic', 503),
        ]);

        try {
            app(OllamaAIService::class)->extract($imagePath);
            $this->fail('Expected provider error was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame('AI_PROVIDER_SERVER_ERROR', $exception->errorCode);
            $this->assertTrue($exception->transient);
            $this->assertSame(503, $exception->httpStatus);
            $this->assertSame('private provider diagnostic', $exception->rawResponse);
            $this->assertStringNotContainsString('private provider diagnostic', $exception->getMessage());
        }

        unlink($imagePath);
    }

    public function test_it_classifies_client_errors_as_non_transient(): void
    {
        $imagePath = $this->temporaryImage('image-bytes');
        Http::fake([
            'ollama.test:11434/api/chat' => Http::response('private provider diagnostic', 404),
        ]);

        try {
            app(OllamaAIService::class)->extract($imagePath);
            $this->fail('Expected provider error was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame('AI_PROVIDER_REQUEST_REJECTED', $exception->errorCode);
            $this->assertFalse($exception->transient);
            $this->assertSame(404, $exception->httpStatus);
        }

        unlink($imagePath);
    }

    public function test_it_classifies_connection_failures_as_transient(): void
    {
        $imagePath = $this->temporaryImage('image-bytes');
        Http::fake([
            'ollama.test:11434/api/chat' => Http::failedConnection(),
        ]);

        try {
            app(OllamaAIService::class)->extract($imagePath);
            $this->fail('Expected provider error was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame('AI_PROVIDER_CONNECTION_FAILED', $exception->errorCode);
            $this->assertTrue($exception->transient);
            $this->assertNull($exception->rawResponse);
        }

        unlink($imagePath);
    }

    public function test_it_rejects_missing_configuration_and_missing_image_without_http_call(): void
    {
        Http::fake();

        config(['ai.ollama.url' => '']);
        try {
            app(OllamaAIService::class)->extract('/does/not/matter');
            $this->fail('Expected configuration error was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame('AI_PROVIDER_NOT_CONFIGURED', $exception->errorCode);
            $this->assertFalse($exception->transient);
        }

        config(['ai.ollama.url' => 'http://ollama.test:11434']);
        try {
            app(OllamaAIService::class)->extract('/missing/image.jpg');
            $this->fail('Expected image error was not thrown.');
        } catch (AiProviderException $exception) {
            $this->assertSame('AI_IMAGE_UNAVAILABLE', $exception->errorCode);
            $this->assertFalse($exception->transient);
        }

        Http::assertNothingSent();
    }

    private function temporaryImage(string $contents): string
    {
        $path = sys_get_temp_dir().'/'.Str::uuid().'.jpg';
        file_put_contents($path, $contents);

        return $path;
    }
}
