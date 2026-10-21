<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

/**
 * Point-in-time occupancy of a set of spaces. Capacity excludes spaces that
 * are out of service; occupied and reserved spaces are both unavailable.
 */
final class Occupancy
{
    /**
     * @param  array<string, int>  $byStatus
     */
    private function __construct(
        public readonly int $total,
        public readonly int $capacity,
        public readonly int $available,
        public readonly int $inUse,
        public readonly array $byStatus,
    ) {}

    /**
     * @param  array<string, int>  $counts  space count keyed by SpaceStatus value
     */
    public static function fromCounts(array $counts): self
    {
        $byStatus = [];
        $total = 0;
        $capacity = 0;

        foreach (SpaceStatus::cases() as $status) {
            $count = max(0, (int) ($counts[$status->value] ?? 0));
            $byStatus[$status->value] = $count;
            $total += $count;

            if ($status->countsTowardsCapacity()) {
                $capacity += $count;
            }
        }

        $inUse = $byStatus[SpaceStatus::Occupied->value] + $byStatus[SpaceStatus::Reserved->value];

        return new self($total, $capacity, $byStatus[SpaceStatus::Available->value], $inUse, $byStatus);
    }

    /**
     * Whole percentage of capacity in use, 0 when there is no capacity.
     */
    public function rate(): int
    {
        if ($this->capacity === 0) {
            return 0;
        }

        return intdiv($this->inUse * 100, $this->capacity);
    }
}
