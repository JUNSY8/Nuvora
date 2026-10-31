<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Infrastructure\Plates;

use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Domain\PlateFormat;
use App\Modules\Vehicles\Domain\PlateRules;
use App\Modules\Vehicles\Domain\VehicleType;

/**
 * Plate formats issued in Colombia (RUNT). Order matters: the first match wins.
 */
final class ColombiaPlateRules implements PlateRules
{
    public function country(): string
    {
        return 'CO';
    }

    public function recognize(Plate $plate): ?PlateFormat
    {
        $formats = [
            ['/^[A-Z]{3}[0-9]{3}$/', 'vehicle', 'Automóvil o camioneta (ABC123)', VehicleType::Car],
            ['/^[A-Z]{3}[0-9]{2}[A-Z]$/', 'motorcycle', 'Motocicleta (ABC12D)', VehicleType::Motorcycle],
            ['/^[A-Z]{3}[0-9]{2}$/', 'motorcycle_legacy', 'Motocicleta, formato anterior (ABC12)', VehicleType::Motorcycle],
            ['/^[0-9]{3}[A-Z]{3}$/', 'motocarro', 'Motocarro o mototaxi (123ABC)', VehicleType::Other],
            ['/^(CD|CC|OI|AT)[0-9]{4}$/', 'diplomatic', 'Cuerpo diplomático (CD1234)', VehicleType::Car],
            ['/^[RS][0-9]{5}$/', 'trailer', 'Remolque o semirremolque (R12345)', VehicleType::Other],
            ['/^T[0-9]{4}$/', 'temporary', 'Placa temporal (T1234)', null],
        ];

        foreach ($formats as [$pattern, $key, $label, $type]) {
            if (preg_match($pattern, $plate->value) === 1) {
                return new PlateFormat($key, $label, $type);
            }
        }

        return null;
    }
}
