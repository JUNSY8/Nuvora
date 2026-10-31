<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\VehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Prices a hypothetical stay with the same engine the exit uses. Entry and
 * exit are whole minutes in the lot's local time, so a past date shows the
 * tariffs that were in force then and a future one the current tariffs.
 */
final class SimulatePricing
{
    private const MAX_DAYS = 62;

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly PricingEngine $engine,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $lotId, VehicleType $vehicleType, ?string $zoneId, string $entryLocal, string $exitLocal, ?string $discountId): Quote
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TARIFFS_VIEW);

        $lot = $this->parking->lot($tenantId, $lotId)
            ?? throw ValidationException::withMessages(['lot_id' => 'El parqueadero no existe en esta empresa.']);

        CreateTariff::assertZone($this->parking, $tenantId, $lot['id'], $zoneId);

        $entry = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $entryLocal, $lot['timezone']);
        $exit = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $exitLocal, $lot['timezone']);

        if (! $entry instanceof CarbonImmutable || ! $exit instanceof CarbonImmutable) {
            throw ValidationException::withMessages(['entry_at' => 'Indica fecha y hora de entrada y de salida.']);
        }

        $entry = $entry->startOfMinute()->utc();
        $exit = $exit->startOfMinute()->utc();

        if ($exit->lessThan($entry)) {
            throw ValidationException::withMessages(['exit_at' => 'La salida debe ser posterior a la entrada.']);
        }

        if ($entry->diffInDays($exit) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['exit_at' => 'La simulación admite estancias de hasta '.self::MAX_DAYS.' días.']);
        }

        return $this->engine->quote($tenantId, new PricingRequest(
            lotId: $lot['id'],
            vehicleType: $vehicleType,
            entryAt: $entry,
            exitAt: $exit,
            stayMinutes: intdiv($exit->getTimestamp() - $entry->getTimestamp(), 60),
            zoneId: $zoneId,
            discountId: $discountId,
        ));
    }
}
