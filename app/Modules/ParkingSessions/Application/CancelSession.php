<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Application\ParkingSpaceAllocator;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Application\VehicleDirectory;
use App\Modules\Vehicles\Domain\VehicleEventType;
use Illuminate\Support\Facades\DB;

/**
 * Voids an open session registered by mistake (wrong plate, duplicate
 * entry). It is a separate permission from closing because a cancelled stay
 * is never charged; the reason is mandatory and audited.
 */
final class CancelSession
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingSpaceAllocator $spaces,
        private readonly VehicleDirectory $vehicles,
        private readonly SessionLookup $lookup,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $sessionId, string $reason): ParkingSession
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_CANCEL);

        $reason = trim($reason);

        $session = DB::transaction(function () use ($actorUserId, $tenantId, $sessionId, $reason): ParkingSession {
            $session = $this->lookup->find($tenantId, $sessionId, lock: true);

            if (! $session->status->canTransitionTo(SessionStatus::Cancelled)) {
                throw RegisterExit::notOpen($session);
            }

            $session->fill([
                'status' => SessionStatus::Cancelled,
                'cancelled_at' => now(),
                'closed_by' => $actorUserId,
                'cancel_reason' => $reason,
            ])->save();

            if ($session->space_id !== null) {
                $this->spaces->release($tenantId, (string) $session->parking_lot_id, (string) $session->space_id);
            }

            $this->vehicles->recordStayEvent($tenantId, (string) $session->vehicle_id, VehicleEventType::SessionCancelled, $actorUserId, [
                'session_id' => (string) $session->id,
                'parking_lot_id' => (string) $session->parking_lot_id,
                'reason' => $reason,
            ]);

            return $session;
        });

        $this->audit->record(
            action: 'parking_session.cancelled',
            resourceType: 'parking_session',
            resourceId: (string) $session->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'parking_lot_id' => (string) $session->parking_lot_id,
                'plate' => $session->plate,
                'reason' => $reason,
            ],
        );

        $this->live->touch(LiveUpdates::tenant($tenantId), LiveUpdates::lot($tenantId, (string) $session->parking_lot_id));

        return $session;
    }
}
