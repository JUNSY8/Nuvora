<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Infrastructure\Persistence\Tariff;
use App\Modules\Tariffs\Infrastructure\Persistence\TariffVersion;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an active tariff with its first version, in force immediately.
 * Amounts are in the lot's currency, which is copied to the tariff.
 */
final class CreateTariff
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $lotId, TariffData $data): Tariff
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TARIFFS_MANAGE);

        $lot = $this->parking->lot($tenantId, $lotId)
            ?? throw ValidationException::withMessages(['parking_lot_id' => 'Selecciona un parqueadero de esta empresa.']);

        self::assertZone($this->parking, $tenantId, $lot['id'], $data->zoneId);
        $attributes = TariffAttributes::from($data, $lot['currency']);

        if (Tariff::query()->where('parking_lot_id', $lot['id'])->where('code', $attributes['code'])->exists()) {
            throw self::codeTaken();
        }

        try {
            $tariff = DB::transaction(function () use ($actorUserId, $tenantId, $lot, $attributes, $data): Tariff {
                $tariff = Tariff::query()->create([
                    'tenant_id' => $tenantId,
                    'parking_lot_id' => $lot['id'],
                    'currency' => $lot['currency'],
                    'code' => $attributes['code'],
                    'name' => $attributes['name'],
                    'status' => TariffStatus::Active,
                    'current_version' => 1,
                    'created_by' => $actorUserId,
                ]);

                TariffVersion::query()->create([
                    ...$attributes,
                    'tenant_id' => $tenantId,
                    'tariff_id' => $tariff->id,
                    'parking_lot_id' => $lot['id'],
                    'version' => 1,
                    'effective_from' => now(),
                    'status' => TariffStatus::Active,
                    'change_note' => $data->changeNote,
                    'created_by' => $actorUserId,
                ]);

                return $tariff;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::codeTaken();
        }

        $this->audit->record(
            action: 'tariff.created',
            resourceType: 'tariff',
            resourceId: (string) $tariff->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['parking_lot_id' => $lot['id'], 'code' => $tariff->code, 'version' => 1],
        );

        return $tariff;
    }

    public static function assertZone(ParkingInfrastructureDirectory $parking, string $tenantId, string $lotId, ?string $zoneId): void
    {
        if ($zoneId !== null && $parking->zone($tenantId, $lotId, $zoneId) === null) {
            throw ValidationException::withMessages(['zone_id' => 'La zona no pertenece a este parqueadero.']);
        }
    }

    public static function codeTaken(): ValidationException
    {
        return ValidationException::withMessages(['code' => 'Ya existe una tarifa con ese código en este parqueadero.']);
    }
}
