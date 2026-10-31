<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Vehicles\Domain\VehicleType;

/**
 * Configuration of a tariff as received. Amounts are minor units of the
 * lot's currency; brackets and windows are validated by the domain.
 */
final class TariffData
{
    /**
     * @param  list<VehicleType>  $vehicleTypes
     * @param  array<mixed>  $windows
     * @param  array<mixed>  $brackets
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $priority,
        public readonly array $vehicleTypes,
        public readonly ?string $zoneId,
        public readonly array $windows,
        public readonly ?int $graceMinutes,
        public readonly array $brackets,
        public readonly int $stepMinutes,
        public readonly int $stepAmount,
        public readonly ?int $dailyMaxAmount,
        public readonly ?string $changeNote,
    ) {}
}
