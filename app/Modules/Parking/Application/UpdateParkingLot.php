<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingCode;
use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\QueryException;

final class UpdateParkingLot
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $lotId, ParkingLotData $data): ParkingLot
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_UPDATE);

        $lot = $this->lookup->lot($tenantId, $lotId);
        $code = ParkingCode::normalize($data->code);
        $tracked = ['name', 'code', 'address', 'city', 'timezone', 'currency', 'grace_period_minutes', 'status'];
        $before = $this->snapshot($lot, $tracked);

        try {
            UniqueCode::guard(
                'Ya existe un parqueadero con ese código.',
                fn (): bool => ParkingLot::query()
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->whereKeyNot($lot->id)
                    ->exists(),
                fn (): bool => $lot->fill(['code' => $code, ...ParkingLotAttributes::from($data)])->save(),
            );
        } catch (QueryException $exception) {
            // Tariffs reference the lot's currency: their amounts would change meaning.
            if (! str_starts_with((string) $exception->getCode(), '23')) {
                throw $exception;
            }

            throw new ParkingRuleViolation(
                "El parqueadero tiene tarifas en {$before['currency']}. Para cobrar en otra moneda crea un parqueadero nuevo.",
                'CURRENCY_IN_USE',
            );
        }

        $after = $this->snapshot($lot, $tracked);

        $this->audit->record(
            action: 'parking_lot.updated',
            resourceType: 'parking_lot',
            resourceId: (string) $lot->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['before' => $before, 'after' => $after],
        );

        return $lot;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function snapshot(ParkingLot $lot, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $value = $lot->getAttribute($key);
            $values[$key] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $values;
    }
}
