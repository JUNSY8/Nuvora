<?php

namespace Tests\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;

trait InteractsWithParking
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function lotPayload(array $overrides = []): array
    {
        return [
            'name' => 'Sede Centro',
            'code' => 'centro',
            'address' => 'Calle 10 # 5-20',
            'city' => 'Bogotá',
            'timezone' => 'America/Bogota',
            'currency' => 'COP',
            'grace_period_minutes' => 15,
            'status' => 'active',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createLot(User $owner, array $overrides = []): string
    {
        return (string) $this->actingAs($owner)
            ->postJson('/api/v1/parking-lots', $this->lotPayload($overrides))
            ->assertCreated()
            ->json('data.lot.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createSpace(User $owner, string $lotId, string $code, array $overrides = []): string
    {
        return (string) $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lotId}/spaces", [
                'code' => $code,
                'type' => 'car',
                'is_reservable' => false,
                'is_assignable' => false,
                ...$overrides,
            ])
            ->assertCreated()
            ->json('data.space.id');
    }
}
