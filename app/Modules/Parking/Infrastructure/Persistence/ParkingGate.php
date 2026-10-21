<?php

declare(strict_types=1);

namespace App\Modules\Parking\Infrastructure\Persistence;

use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\GateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'parking_lot_id', 'name', 'code', 'direction', 'status'])]
class ParkingGate extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => GateDirection::class,
            'status' => GateStatus::class,
        ];
    }
}
