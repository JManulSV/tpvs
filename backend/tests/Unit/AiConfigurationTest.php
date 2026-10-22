<?php

namespace Tests\Unit;

use App\Domain\AI\AIService;
use App\Domain\AI\OllamaAIService;
use Tests\TestCase;

class AiConfigurationTest extends TestCase
{
    public function test_ai_provider_defaults_are_available_without_an_ollama_server(): void
    {
        $this->assertSame('ollama', config('ai.provider'));
        $this->assertSame('', config('ai.ollama.url'));
        $this->assertSame('qwen2.5vl:3b', config('ai.ollama.model'));
        $this->assertSame(300, config('ai.ollama.timeout'));
        $this->assertSame(360, config('queue.connections.database.retry_after'));
    }

    public function test_ai_service_is_bound_to_the_ollama_implementation(): void
    {
        $this->assertTrue($this->app->bound(AIService::class));

        $binding = $this->app->getBindings()[AIService::class];
        $concrete = (new \ReflectionFunction($binding['concrete']))->getStaticVariables()['concrete'];

        $this->assertSame(OllamaAIService::class, $concrete);
    }

    public function test_ai_provider_settings_can_be_overridden_without_connecting_to_ollama(): void
    {
        config([
            'ai.provider' => 'ollama',
            'ai.ollama.url' => 'http://ollama.internal:11434',
            'ai.ollama.model' => 'minicpm-v:8b',
            'ai.ollama.timeout' => 240,
        ]);

        $this->assertSame('ollama', config('ai.provider'));
        $this->assertSame('http://ollama.internal:11434', config('ai.ollama.url'));
        $this->assertSame('minicpm-v:8b', config('ai.ollama.model'));
        $this->assertSame(240, config('ai.ollama.timeout'));
    }
}
