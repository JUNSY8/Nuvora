<?php

namespace Tests\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

trait InteractsWithPayments
{
    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    protected function exitWith(User $actor, string $sessionId, array $overrides = [], array $headers = []): TestResponse
    {
        $response = $this->actingAs($actor)->withHeaders($headers)->postJson("/api/v1/parking-sessions/{$sessionId}/exit", $overrides);
        $this->flushHeaders();

        return $response;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    protected function pay(User $actor, string $payableType, string $payableId, array $overrides = [], array $headers = []): TestResponse
    {
        $response = $this->actingAs($actor)->withHeaders($headers)->postJson('/api/v1/payments', [
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'method' => 'cash',
            ...$overrides,
        ]);
        $this->flushHeaders();

        return $response;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    protected function refund(User $actor, string $paymentId, array $overrides = [], array $headers = []): TestResponse
    {
        $response = $this->actingAs($actor)->withHeaders($headers)->postJson("/api/v1/payments/{$paymentId}/refunds", [
            'reason' => 'Cobro duplicado',
            ...$overrides,
        ]);
        $this->flushHeaders();

        return $response;
    }
}
