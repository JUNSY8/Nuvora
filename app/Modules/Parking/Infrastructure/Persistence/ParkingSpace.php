<?php

declare(strict_types=1);

namespace App\Modules\Parking\Infrastructure\Persistence;

use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Domain\SpaceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'parking_lot_id',
    'zone_id',
    'code',
    'type',
    'status',
    'floor',
    'is_reservable',
    'is_assignable',
    'status_changed_at',
])]
class ParkingSpace extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SpaceType::class,
            'status' => SpaceStatus::class,
            'is_reservable' => 'boolean',
            'is_assignable' => 'boolean',
            'status_changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ParkingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ParkingZone::class, 'zone_id');
    }
}
