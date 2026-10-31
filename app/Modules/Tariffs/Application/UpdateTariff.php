<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Tariffs\Infrastructure\Persistence\Tariff;
use App\Modules\Tariffs\Infrastructure\Persistence\TariffVersion;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A change never rewrites a version: it publishes the next one, in force
 * from now. Stays that entered earlier keep the version in force at their
 * entry. Saving the same configuration publishes nothing.
 */
final class UpdateTariff
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly TariffLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $tariffId, TariffData $data): Tariff
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TARIFFS_MANAGE);

        $tariff = $this->lookup->tariff($tenantId, $tariffId);
        CreateTariff::assertZone($this->parking, $tenantId, (string) $tariff->parking_lot_id, $data->zoneId);
        $attributes = TariffAttributes::from($data, $tariff->currency);

        $taken = Tariff::query()
            ->where('parking_lot_id', $tariff->parking_lot_id)
            ->where('code', $attributes['code'])
            ->whereKeyNot($tariff->id)
            ->exists();

        if ($taken) {
            throw CreateTariff::codeTaken();
        }

        try {
            [$tariff, $from] = DB::transaction(function () use ($actorUserId, $tenantId, $tariffId, $attributes, $data): array {
                // Lock so two editors cannot both publish the same version number.
                $tariff = $this->lookup->tariff($tenantId, $tariffId, lock: true);
                $current = self::current($tariff);

                if (TariffAttributes::ofVersion($current) == $attributes) {
                    return [$tariff, null];
                }

                self::publish($tariff, $current, $attributes, $actorUserId, $data->changeNote);

                return [$tariff, $current->version];
            });
        } catch (UniqueConstraintViolationException) {
            throw CreateTariff::codeTaken();
        }

        /** @var Tariff $tariff */
        if ($from !== null) {
            $this->audit->record(
                action: 'tariff.updated',
                resourceType: 'tariff',
                resourceId: (string) $tariff->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: ['code' => $tariff->code, 'from_version' => $from, 'to_version' => $tariff->current_version, 'note' => $data->changeNote],
            );
        }

        return $tariff;
    }

    public static function current(Tariff $tariff): TariffVersion
    {
        return TariffVersion::query()
            ->where('tariff_id', $tariff->id)
            ->where('version', $tariff->current_version)
            ->firstOrFail();
    }

    /**
     * Inserts the next version and mirrors its identity on the tariff. The
     * caller holds the tariff's row lock.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function publish(Tariff $tariff, TariffVersion $current, array $changes, string $actorUserId, ?string $note): TariffVersion
    {
        $next = $current->version + 1;

        $version = TariffVersion::query()->create([
            ...TariffAttributes::ofVersion($current),
            'status' => $current->status,
            ...$changes,
            'tenant_id' => $tariff->tenant_id,
            'tariff_id' => $tariff->id,
            'parking_lot_id' => $tariff->parking_lot_id,
            'version' => $next,
            'effective_from' => now(),
            'change_note' => $note,
            'created_by' => $actorUserId,
        ]);

        $tariff->fill([
            'code' => $version->code,
            'name' => $version->name,
            'status' => $version->status,
            'current_version' => $next,
        ])->save();

        return $version;
    }
}
