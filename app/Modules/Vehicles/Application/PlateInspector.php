<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\CountryCode;
use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;
use App\Modules\Vehicles\Infrastructure\Plates\CountryPlateRules;

/**
 * Answers how a typed plate will be stored, which country format it matches
 * and whether the tenant already has it. Used for live form feedback and,
 * from 0.5, for manual identification at the gate.
 */
final class PlateInspector
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly CountryPlateRules $rules,
    ) {}

    /**
     * @return array{plate: string, country: string, format: array{key: string, label: string}|null, rules_available: bool, suggested_type: string|null, existing: array{id: string, plate: string, status: string}|null}
     */
    public function inspect(string $actorUserId, string $tenantId, string $input, string $country): array
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::VEHICLES_VIEW);

        $plate = Plate::fromInput($input);
        $country = CountryCode::normalize($country);
        $format = $this->rules->recognize($country, $plate);

        $existing = Vehicle::query()
            ->where('tenant_id', $tenantId)
            ->where('country', $country)
            ->where('plate', $plate->value)
            ->first(['id', 'plate', 'status']);

        return [
            'plate' => $plate->value,
            'country' => $country,
            'format' => $format === null ? null : ['key' => $format->key, 'label' => $format->label],
            'rules_available' => $this->rules->supports($country),
            'suggested_type' => $format?->suggestedType?->value,
            'existing' => $existing === null ? null : [
                'id' => (string) $existing->id,
                'plate' => $existing->plate,
                'status' => $existing->status->value,
            ],
        ];
    }
}
