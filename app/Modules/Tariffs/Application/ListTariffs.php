<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Tariffs\Infrastructure\Persistence\Tariff;
use App\Modules\Tariffs\Infrastructure\Persistence\TariffVersion;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ListTariffs
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
    ) {}

    /**
     * Tariffs of one lot with their current version, active first.
     *
     * @return Collection<int, Tariff>
     */
    public function handle(string $actorUserId, string $tenantId, string $lotId): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TARIFFS_VIEW);

        $lot = $this->parking->lot($tenantId, $lotId)
            ?? throw ValidationException::withMessages(['lot_id' => 'El parqueadero no existe en esta empresa.']);

        return Tariff::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lot['id'])
            ->with('currentVersion')
            ->orderBy('status')
            ->orderBy('code')
            ->get();
    }

    /**
     * Every version of a tariff, newest first, with who published it.
     *
     * @return Collection<int, TariffVersion>
     */
    public function versions(string $actorUserId, string $tenantId, Tariff $tariff): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TARIFFS_VIEW);

        return TariffVersion::query()
            ->where('tenant_id', $tenantId)
            ->where('tariff_id', $tariff->id)
            ->with('author:id,name')
            ->orderByDesc('version')
            ->get();
    }
}
