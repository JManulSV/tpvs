<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Test that the health endpoint returns a successful response.
     */
    public function test_the_health_endpoint_returns_ok(): void
    {
        $this->getJson('api/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'backend',
            ]);
    }

    public function test_the_framework_health_endpoint_returns_ok(): void
    {
        $this->get('/up')->assertOk();
    }
}
