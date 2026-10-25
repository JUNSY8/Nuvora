<?php

namespace Tests\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithMemberships
{
    /**
     * A monthly plan of $120.000 COP for one car, without restrictions.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createPlan(User $owner, array $overrides = []): string
    {
        return (string) $this->actingAs($owner)
            ->postJson('/api/v1/membership-plans', $this->planPayload($overrides))
            ->assertCreated()
            ->json('data.plan.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function planPayload(array $overrides = []): array
    {
        return [
            'code' => 'mensual',
            'name' => 'Mensual carros',
            'category' => 'monthly',
            'billing_period' => 'monthly',
            'price_amount' => 12000000,
            'currency' => 'COP',
            'grace_days' => 3,
            'max_vehicles' => 1,
            'capacity' => null,
            'parking_lot_ids' => [],
            'zone_ids' => [],
            'vehicle_types' => [],
            'windows' => [],
            'description' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  list<string>  $plates
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<JsonResponse>
     */
    protected function sell(User $actor, string $planId, string $customerId, array $plates, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/v1/memberships', [
            'plan_id' => $planId,
            'customer_id' => $customerId,
            'starts_on' => '2026-11-01',
            'vehicles' => array_map(fn (string $plate): array => ['plate' => $plate, 'country' => 'CO', 'vehicle_type' => 'car'], $plates),
            'auto_renew' => true,
            ...$overrides,
        ]);
    }

    /**
     * Pays every due period of a membership by transfer, so its coverage
     * does not lapse for lack of payment.
     */
    protected function payPeriods(User $actor, string $membershipId): void
    {
        foreach (DB::table('membership_periods')->where('membership_id', $membershipId)->where('status', 'due')->pluck('id') as $periodId) {
            $this->actingAs($actor)->postJson('/api/v1/payments', [
                'payable_type' => 'membership_period',
                'payable_id' => $periodId,
                'method' => 'transfer',
            ])->assertCreated();
        }
    }

    protected function membershipStatus(string $membershipId): string
    {
        return (string) DB::table('memberships')->where('id', $membershipId)->value('status');
    }
}
