<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Infrastructure\Persistence\VehicleEvent;

/**
 * Appends entries to the vehicle history. Unlike the audit log, which answers
 * "who changed what" for security, the timeline is operational data shown to
 * operators and will also receive sessions and LPR reads.
 */
final class VehicleTimeline
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(string $tenantId, string $vehicleId, VehicleEventType $type, ?string $actorUserId, array $data = []): void
    {
        VehicleEvent::query()->create([
            'tenant_id' => $tenantId,
            'vehicle_id' => $vehicleId,
            'type' => $type,
            'actor_user_id' => $actorUserId,
            'data' => $data === [] ? null : $data,
            'occurred_at' => now(),
        ]);
    }
}
