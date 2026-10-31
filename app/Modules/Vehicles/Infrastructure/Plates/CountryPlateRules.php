<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Infrastructure\Plates;

use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Domain\PlateFormat;
use App\Modules\Vehicles\Domain\PlateRules;

/**
 * Registry of the countries with known plate formats. Countries without rules
 * still work: their plates are normalized but not described.
 */
final class CountryPlateRules
{
    /** @var array<string, PlateRules> */
    private array $rules = [];

    public function __construct()
    {
        foreach ([new ColombiaPlateRules] as $rules) {
            $this->rules[$rules->country()] = $rules;
        }
    }

    public function recognize(string $country, Plate $plate): ?PlateFormat
    {
        return ($this->rules[$country] ?? null)?->recognize($plate);
    }

    public function supports(string $country): bool
    {
        return isset($this->rules[$country]);
    }
}
