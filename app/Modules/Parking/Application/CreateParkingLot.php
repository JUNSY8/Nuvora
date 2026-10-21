<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingCode;
use App\Modules\Parking\Domain\WeeklySchedule;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLotOperatingHour;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Support\Facades\DB;

final class CreateParkingLot
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, ParkingLotData $data): ParkingLot
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_CREATE);

        $code = ParkingCode::normalize($data->code);

        $lot = UniqueCode::guard(
            'Ya existe un parqueadero con ese código.',
            fn (): bool => ParkingLot::query()->where('tenant_id', $tenantId)->where('code', $code)->exists(),
            fn (): ParkingLot => DB::transaction(function () use ($tenantId, $code, $data): ParkingLot {
                $lot = ParkingLot::query()->create([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    ...ParkingLotAttributes::from($data),
                ]);

                // New lots start open around the clock until the schedule is set.
                foreach (WeeklySchedule::alwaysOpen()->days as $day) {
                    ParkingLotOperatingHour::query()->create([
                        'tenant_id' => $tenantId,
                        'parking_lot_id' => $lot->id,
                        'day_of_week' => $day->dayOfWeek,
                        'mode' => $day->mode,
                        'opens_at' => null,
                        'closes_at' => null,
                    ]);
                }

                return $lot;
            }),
        );

        $this->audit->record(
            action: 'parking_lot.created',
            resourceType: 'parking_lot',
            resourceId: (string) $lot->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['code' => $lot->code, 'name' => $lot->name, 'timezone' => $lot->timezone],
        );

        return $lot;
    }
}
