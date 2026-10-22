<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'parking_lot_id',
    'enabled',
    'buffer_minutes',
    'hold_minutes',
    'arrival_tolerance_minutes',
    'min_duration_minutes',
    'max_duration_minutes',
    'max_advance_days',
    'within_operating_hours',
])]
class LotReservationPolicy extends Model
{
    use HasUlids;

    protected $table = 'reservation_policies';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'buffer_minutes' => 'integer',
            'hold_minutes' => 'integer',
            'arrival_tolerance_minutes' => 'integer',
            'min_duration_minutes' => 'integer',
            'max_duration_minutes' => 'integer',
            'max_advance_days' => 'integer',
            'within_operating_hours' => 'boolean',
        ];
    }
}
