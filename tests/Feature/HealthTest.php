<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_returns_ok_and_a_request_id(): void
    {
        $response = $this->getJson('/health/live');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok');

        $requestId = $response->json('request_id');

        $this->assertIsString($requestId);
        $this->assertNotSame('', $requestId);
        $response->assertHeader('X-Request-Id', $requestId);
    }

    public function test_live_reuses_a_valid_request_id(): void
    {
        $requestId = '11111111-1111-4111-8111-111111111111';

        $this->getJson('/health/live', ['X-Request-Id' => $requestId])
            ->assertOk()
            ->assertHeader('X-Request-Id', $requestId)
            ->assertJsonPath('request_id', $requestId);
    }

    public function test_ready_fails_when_redis_is_unavailable(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertStatus(503)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertJsonPath('data.checks.redis', 'failed')
            ->assertJsonPath('data.checks.queue', 'ok')
            ->assertJsonPath('data.checks.storage', 'ok');
    }

    public function test_ready_succeeds_when_dependencies_respond(): void
    {
        $connection = new class
        {
            public function ping(): string
            {
                return 'PONG';
            }
        };

        Redis::shouldReceive('connection')->once()->andReturn($connection);

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.redis', 'ok');
    }
}
