<?php

declare(strict_types=1);

namespace App\Modules\Parking\Infrastructure\Persistence;

use App\Modules\Parking\Domain\ParkingLotStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'name',
    'code',
    'description',
    'address',
    'city',
    'latitude',
    'longitude',
    'timezone',
    'currency',
    'grace_period_minutes',
    'status',
])]
class ParkingLot extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ParkingLotStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'grace_period_minutes' => 'integer',
        ];
    }

    /**
     * @return HasMany<ParkingLotOperatingHour, $this>
     */
    public function operatingHours(): HasMany
    {
        return $this->hasMany(ParkingLotOperatingHour::class)->orderBy('day_of_week');
    }

    /**
     * @return HasMany<ParkingZone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(ParkingZone::class);
    }

    /**
     * @return HasMany<ParkingSpace, $this>
     */
    public function spaces(): HasMany
    {
        return $this->hasMany(ParkingSpace::class);
    }

    /**
     * @return HasMany<ParkingGate, $this>
     */
    public function gates(): HasMany
    {
        return $this->hasMany(ParkingGate::class);
    }
}
