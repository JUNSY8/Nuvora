<?php

namespace Tests\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithParkingSessions
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createGate(User $owner, string $lotId, string $code, string $direction, array $overrides = []): string
    {
        return (string) $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lotId}/gates", [
                'name' => "Puerta {$code}",
                'code' => $code,
                'direction' => $direction,
                'status' => 'active',
                ...$overrides,
            ])
            ->assertCreated()
            ->json('data.gate.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    protected function enter(User $actor, string $lotId, string $plate, array $overrides = [], array $headers = []): TestResponse
    {
        $response = $this->actingAs($actor)->withHeaders($headers)->postJson('/api/v1/parking-sessions/entries', [
            'lot_id' => $lotId,
            'plate' => $plate,
            'country' => 'CO',
            'vehicle_type' => 'car',
            ...$overrides,
        ]);

        // Default headers persist across requests of a test; keys must not leak to the next one.
        $this->flushHeaders();

        return $response;
    }

    protected function spaceStatus(string $spaceId): string
    {
        return (string) DB::table('parking_spaces')->where('id', $spaceId)->value('status');
    }
}
