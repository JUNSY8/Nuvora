<?php

namespace Tests\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithReservations
{
    /**
     * Books a car for a window given in the lot's local time.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    protected function reserve(User $actor, string $lotId, string $plate, string $startsAt, string $endsAt, array $overrides = [], array $headers = []): TestResponse
    {
        $response = $this->actingAs($actor)->withHeaders($headers)->postJson('/api/v1/reservations', [
            'lot_id' => $lotId,
            'plate' => $plate,
            'country' => 'CO',
            'vehicle_type' => 'car',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            ...$overrides,
        ]);

        $this->flushHeaders();

        return $response;
    }

    protected function createZone(User $owner, string $lotId, string $code): string
    {
        return (string) $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lotId}/zones", ['name' => "Zona {$code}", 'code' => $code, 'description' => null, 'is_active' => true, 'sort_order' => 0])
            ->assertCreated()
            ->json('data.zone.id');
    }

    protected function reservationStatus(string $reservationId): string
    {
        return (string) DB::table('reservations')->where('id', $reservationId)->value('status');
    }

    protected function sweepReservations(): void
    {
        $this->artisan('reservations:sweep')->assertSuccessful();
    }
}
