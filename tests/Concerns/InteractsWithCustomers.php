<?php

namespace Tests\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;

trait InteractsWithCustomers
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createCustomer(User $actor, array $overrides = []): string
    {
        return (string) $this->actingAs($actor)
            ->postJson('/api/v1/customers', [
                'type' => 'individual',
                'name' => 'Laura Gómez',
                ...$overrides,
            ])
            ->assertCreated()
            ->json('data.customer.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createVehicle(User $actor, string $plate, array $overrides = []): string
    {
        return (string) $this->actingAs($actor)
            ->postJson('/api/v1/vehicles', [
                'plate' => $plate,
                'country' => 'CO',
                'type' => 'car',
                ...$overrides,
            ])
            ->assertCreated()
            ->json('data.vehicle.id');
    }
}
