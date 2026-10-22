<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Application\ParkingSpaceAllocator;
use App\Modules\ParkingSessions\Domain\SessionRuleViolation;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\ParkingSessions\Domain\SpaceCompatibility;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Assigns or changes the space of an open session (the vehicle parked
 * somewhere else, or entered without a space). The new space is occupied
 * and the old one freed in the same transaction.
 */
final class MoveSession
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingSpaceAllocator $spaces,
        private readonly SessionLookup $lookup,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $sessionId, string $spaceId): ParkingSession
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_CREATE);

        try {
            [$session, $from] = DB::transaction(function () use ($tenantId, $sessionId, $spaceId): array {
                $session = $this->lookup->find($tenantId, $sessionId, lock: true);

                if ($session->status !== SessionStatus::Open) {
                    throw RegisterExit::notOpen($session);
                }

                if ((string) $session->space_id === $spaceId) {
                    return [$session, null];
                }

                $from = $session->space_code;
                $space = $this->spaces->occupy($tenantId, (string) $session->parking_lot_id, $spaceId);

                if (! SpaceCompatibility::accepts($space['type'], VehicleType::from($session->vehicle_type))) {
                    throw new SessionRuleViolation("El espacio {$space['code']} no admite este tipo de vehículo.", 'SPACE_INCOMPATIBLE');
                }

                if ($session->space_id !== null) {
                    $this->spaces->release($tenantId, (string) $session->parking_lot_id, (string) $session->space_id);
                }

                $session->fill(['space_id' => $space['id'], 'space_code' => $space['code']])->save();

                return [$session, $from ?? ''];
            });
        } catch (UniqueConstraintViolationException) {
            throw new SessionRuleViolation('Otra sesión tomó ese espacio al mismo tiempo. Elige otro.', 'ENTRY_CONFLICT', 409);
        }

        /** @var ParkingSession $session */
        if ($from !== null) {
            $this->audit->record(
                action: 'parking_session.space_changed',
                resourceType: 'parking_session',
                resourceId: (string) $session->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: ['plate' => $session->plate, 'from' => $from === '' ? null : $from, 'to' => $session->space_code],
            );

            $this->live->touch(LiveUpdates::tenant($tenantId), LiveUpdates::lot($tenantId, (string) $session->parking_lot_id));
        }

        return $session;
    }
}
