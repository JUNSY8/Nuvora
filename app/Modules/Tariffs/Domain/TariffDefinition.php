<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeInterface;

/**
 * One version of a tariff: who it applies to and how it prices. A tariff
 * with no vehicle types, zone or windows applies to every stay of its lot;
 * each filter narrows it. The entry moment decides the window, so a stay
 * that starts at night is priced by the night tariff until it leaves.
 */
final class TariffDefinition
{
    /**
     * @param  list<VehicleType>  $vehicleTypes
     * @param  list<TimeWindow>  $windows
     */
    public function __construct(
        public readonly string $tariffId,
        public readonly string $versionId,
        public readonly int $version,
        public readonly string $code,
        public readonly string $name,
        public readonly TariffStatus $status,
        public readonly int $priority,
        public readonly array $vehicleTypes,
        public readonly ?string $zoneId,
        public readonly array $windows,
        public readonly ?int $graceMinutes,
        public readonly RateSchedule $rates,
    ) {}

    /**
     * @param  DateTimeInterface  $entryLocal  the entry in the lot's timezone
     */
    public function appliesTo(VehicleType $vehicleType, ?string $zoneId, DateTimeInterface $entryLocal): bool
    {
        if ($this->status !== TariffStatus::Active) {
            return false;
        }

        if ($this->vehicleTypes !== [] && ! in_array($vehicleType, $this->vehicleTypes, true)) {
            return false;
        }

        if ($this->zoneId !== null && $this->zoneId !== $zoneId) {
            return false;
        }

        if ($this->windows === []) {
            return true;
        }

        foreach ($this->windows as $window) {
            if ($window->contains($entryLocal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Breaks ties between tariffs of equal priority: a zone is the narrowest
     * filter, then a time window, then the vehicle type.
     */
    public function specificity(): int
    {
        return ($this->zoneId !== null ? 4 : 0)
            + ($this->windows !== [] ? 2 : 0)
            + ($this->vehicleTypes !== [] ? 1 : 0);
    }

    /**
     * The tariff that prices a stay: highest priority, then the most
     * specific, then the code, so the choice never depends on query order.
     *
     * @param  iterable<self>  $candidates
     * @param  DateTimeInterface  $entryLocal  the entry in the lot's timezone
     */
    public static function select(iterable $candidates, VehicleType $vehicleType, ?string $zoneId, DateTimeInterface $entryLocal): ?self
    {
        $best = null;

        foreach ($candidates as $candidate) {
            if (! $candidate->appliesTo($vehicleType, $zoneId, $entryLocal)) {
                continue;
            }

            if ($best === null || $candidate->ranksAbove($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function ranksAbove(self $other): bool
    {
        return [$other->priority, $other->specificity(), $this->code] < [$this->priority, $this->specificity(), $other->code];
    }
}
