<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\OperatingDay;
use App\Modules\Parking\Domain\OperatingDayMode;
use App\Modules\Parking\Domain\WeeklySchedule;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLotOperatingHour;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Support\Facades\DB;

final class ReplaceOperatingHours
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<array{day_of_week: int, mode: string, opens_at?: string|null, closes_at?: string|null}>  $days
     */
    public function handle(string $actorUserId, string $tenantId, string $lotId, array $days): ParkingLot
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_UPDATE);

        $schedule = WeeklySchedule::fromDays(array_map(
            fn (array $day): OperatingDay => OperatingDay::create(
                (int) $day['day_of_week'],
                OperatingDayMode::from($day['mode']),
                $day['opens_at'] ?? null,
                $day['closes_at'] ?? null,
            ),
            $days,
        ));

        $lot = DB::transaction(function () use ($tenantId, $lotId, $schedule): ParkingLot {
            // The row lock serialises two editors saving the same schedule.
            $lot = $this->lookup->lot($tenantId, $lotId, lock: true);

            ParkingLotOperatingHour::query()->where('parking_lot_id', $lot->id)->delete();

            foreach ($schedule->days as $day) {
                ParkingLotOperatingHour::query()->create([
                    'tenant_id' => $tenantId,
                    'parking_lot_id' => $lot->id,
                    'day_of_week' => $day->dayOfWeek,
                    'mode' => $day->mode,
                    'opens_at' => $day->opensAt,
                    'closes_at' => $day->closesAt,
                ]);
            }

            return $lot;
        });

        $this->audit->record(
            action: 'parking_lot.hours_updated',
            resourceType: 'parking_lot',
            resourceId: (string) $lot->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'days' => array_values(array_map(fn (OperatingDay $day): array => [
                    'day_of_week' => $day->dayOfWeek,
                    'mode' => $day->mode->value,
                    'opens_at' => $day->opensAt,
                    'closes_at' => $day->closesAt,
                ], $schedule->days)),
            ],
        );

        return $lot->load('operatingHours');
    }
}
