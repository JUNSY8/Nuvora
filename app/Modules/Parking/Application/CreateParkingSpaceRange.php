<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingCode;
use App\Modules\Parking\Domain\SpaceCodeRange;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateParkingSpaceRange
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * Creates every space of the range or none of them.
     *
     * @return int number of spaces created
     */
    public function handle(
        string $actorUserId,
        string $tenantId,
        string $lotId,
        string $prefix,
        int $from,
        int $to,
        int $padding,
        ParkingSpaceData $data,
    ): int {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_CREATE);

        $lot = $this->lookup->lot($tenantId, $lotId);
        $this->lookup->assertZoneInLot($tenantId, (string) $lot->id, $data->zoneId);

        $prefix = trim($prefix) === '' ? '' : ParkingCode::normalize($prefix);
        $codes = SpaceCodeRange::generate($prefix, $from, $to, $padding);

        $taken = ParkingSpace::query()
            ->where('parking_lot_id', $lot->id)
            ->whereIn('code', $codes)
            ->orderBy('code')
            ->limit(5)
            ->pluck('code')
            ->all();

        if ($taken !== []) {
            throw $this->conflict($taken);
        }

        $now = now();
        $rows = array_map(fn (string $code): array => [
            'id' => strtolower((string) Str::ulid()),
            'tenant_id' => $tenantId,
            'parking_lot_id' => (string) $lot->id,
            'zone_id' => $data->zoneId,
            'code' => $code,
            'type' => $data->type->value,
            'status' => SpaceStatus::Available->value,
            'floor' => $data->floor,
            'is_reservable' => $data->isReservable,
            'is_assignable' => $data->isAssignable,
            'status_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $codes);

        try {
            DB::transaction(function () use ($rows): void {
                foreach (array_chunk($rows, 100) as $chunk) {
                    ParkingSpace::query()->insert($chunk);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->conflict([]);
        }

        $this->audit->record(
            action: 'parking_space.bulk_created',
            resourceType: 'parking_lot',
            resourceId: (string) $lot->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'count' => count($codes),
                'first_code' => $codes[0],
                'last_code' => $codes[count($codes) - 1],
                'type' => $data->type->value,
                'zone_id' => $data->zoneId,
            ],
        );

        return count($codes);
    }

    /**
     * @param  list<string>  $codes
     */
    private function conflict(array $codes): ValidationException
    {
        $message = $codes === []
            ? 'Algunos códigos del rango ya existen en este parqueadero.'
            : 'Estos códigos ya existen en este parqueadero: '.implode(', ', $codes).'.';

        return ValidationException::withMessages(['prefix' => $message]);
    }
}
