<?php

declare(strict_types=1);

namespace App\Modules\Parking\Infrastructure\Persistence;

use App\Modules\Parking\Domain\OperatingDayMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'parking_lot_id', 'day_of_week', 'mode', 'opens_at', 'closes_at'])]
class ParkingLotOperatingHour extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'mode' => OperatingDayMode::class,
        ];
    }
}
