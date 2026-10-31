<?php

namespace Tests\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

trait InteractsWithTariffs
{
    /** Pesos to COP minor units. */
    protected static function cop(int $pesos): int
    {
        return $pesos * 100;
    }

    /**
     * $3.000 per started hour, for every vehicle, zone and time.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function tariffPayload(array $overrides = []): array
    {
        return [
            'code' => 'general',
            'name' => 'General',
            'priority' => 0,
            'vehicle_types' => [],
            'zone_id' => null,
            'windows' => [],
            'grace_minutes' => null,
            'brackets' => [],
            'step_minutes' => 60,
            'step_amount' => self::cop(3000),
            'daily_max_amount' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createTariff(User $owner, string $lotId, array $overrides = []): string
    {
        return (string) $this->actingAs($owner)
            ->postJson('/api/v1/tariffs', ['parking_lot_id' => $lotId, ...$this->tariffPayload($overrides)])
            ->assertCreated()
            ->json('data.tariff.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createDiscount(User $owner, array $overrides = []): string
    {
        return (string) $this->actingAs($owner)
            ->postJson('/api/v1/discounts', [
                'parking_lot_id' => null,
                'code' => 'convenio',
                'name' => 'Convenio',
                'type' => 'percentage',
                'value' => 2000,
                'currency' => null,
                'application' => 'manual',
                'vehicle_types' => [],
                'days' => [],
                'valid_from' => null,
                'valid_until' => null,
                ...$overrides,
            ])
            ->assertCreated()
            ->json('data.discount.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<JsonResponse>
     */
    protected function simulate(User $actor, string $lotId, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/v1/tariffs/simulate', [
            'lot_id' => $lotId,
            'vehicle_type' => 'car',
            'zone_id' => null,
            'entry_at' => '2026-11-04T10:00',
            'exit_at' => '2026-11-04T12:30',
            ...$overrides,
        ]);
    }
}
