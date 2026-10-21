<?php

declare(strict_types=1);

namespace App\Modules\Parking\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'parking_lot_id', 'name', 'code', 'description', 'is_active', 'sort_order'])]
class ParkingZone extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ParkingSpace, $this>
     */
    public function spaces(): HasMany
    {
        return $this->hasMany(ParkingSpace::class, 'zone_id');
    }
}
