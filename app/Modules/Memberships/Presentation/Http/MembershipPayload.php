<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Presentation\Http;

use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Memberships\Application\MembershipCalendar;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipPeriod;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipPlan;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipVehicle;
use App\Modules\Vehicles\Application\VehicleDirectory;

final class MembershipPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function plan(MembershipPlan $plan, ?int $sold = null): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'category' => $plan->category->value,
            'billing_period' => $plan->billing_period->value,
            'price_amount' => $plan->price_amount,
            'currency' => $plan->currency,
            'grace_days' => $plan->grace_days,
            'max_vehicles' => $plan->max_vehicles,
            'capacity' => $plan->capacity,
            'sold' => $sold,
            'parking_lot_ids' => $plan->parking_lot_ids,
            'zone_ids' => $plan->zone_ids,
            'vehicle_types' => $plan->vehicle_types,
            'windows' => $plan->windows,
            'status' => $plan->status->value,
            'description' => $plan->description,
        ];
    }

    /**
     * @param  iterable<Membership>  $memberships
     * @return list<array<string, mixed>>
     */
    public static function many(string $tenantId, iterable $memberships, bool $withHistory = false): array
    {
        $customerIds = [];
        $vehicleIds = [];

        foreach ($memberships as $membership) {
            $customerIds[(string) $membership->customer_id] = true;

            foreach ($membership->vehicles as $vehicle) {
                $vehicleIds[(string) $vehicle->vehicle_id] = true;
            }
        }

        $customers = app(CustomerDirectory::class)->summaries($tenantId, array_keys($customerIds));
        $vehicles = app(VehicleDirectory::class)->summaries($tenantId, array_keys($vehicleIds));
        $items = [];

        foreach ($memberships as $membership) {
            $customer = $customers[(string) $membership->customer_id] ?? null;

            $item = [
                'id' => $membership->id,
                'code' => $membership->code,
                'status' => $membership->status->value,
                'status_reason' => $membership->status_reason,
                'plan' => ['id' => $membership->plan->id, 'code' => $membership->plan->code, 'name' => $membership->plan->name, 'category' => $membership->plan->category->value],
                'customer' => $customer === null ? null : ['id' => $customer['id'], 'name' => $customer['name'], 'type' => $customer['type']],
                'billing_period' => $membership->billing_period->value,
                'price_amount' => $membership->price_amount,
                'currency' => $membership->currency,
                'grace_days' => $membership->grace_days,
                'starts_on' => $membership->starts_on->format('Y-m-d'),
                'current_period_start' => $membership->current_period_start->format('Y-m-d'),
                'current_period_end' => $membership->current_period_end->format('Y-m-d'),
                'covered_until' => MembershipCalendar::coveredUntil($membership)->format('Y-m-d'),
                'ended_on' => $membership->ended_on?->format('Y-m-d'),
                'auto_renew' => $membership->auto_renew,
                'assigned_space' => $membership->assigned_space_id === null ? null : ['id' => $membership->assigned_space_id, 'code' => $membership->assigned_space_code, 'parking_lot_id' => $membership->assigned_lot_id],
                'vehicles' => $membership->vehicles->map(fn (MembershipVehicle $vehicle): array => [
                    'id' => $vehicle->vehicle_id,
                    'plate' => $vehicle->plate,
                    'country' => $vehicles[(string) $vehicle->vehicle_id]['country'] ?? null,
                    'type' => $vehicles[(string) $vehicle->vehicle_id]['type'] ?? null,
                    'added_at' => $vehicle->added_at->toIso8601String(),
                ])->values()->all(),
                'notes' => $membership->notes,
                'created_at' => $membership->created_at?->toIso8601String(),
            ];

            if ($withHistory) {
                $item['periods'] = $membership->periods->map(fn (MembershipPeriod $period): array => [
                    'sequence' => $period->sequence,
                    'starts_on' => $period->starts_on->format('Y-m-d'),
                    'ends_on' => $period->ends_on->format('Y-m-d'),
                    'amount' => $period->amount,
                    'currency' => $period->currency,
                    'status' => $period->status,
                    'origin' => $period->origin,
                ])->values()->all();
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(string $tenantId, Membership $membership): array
    {
        return self::many($tenantId, [$membership->load(['plan', 'vehicles', 'periods'])], true)[0];
    }
}
