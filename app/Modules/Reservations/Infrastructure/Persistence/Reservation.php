<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Infrastructure\Persistence;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Reservations\Domain\ReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'parking_lot_id',
    'code',
    'status',
    'vehicle_id',
    'customer_id',
    'plate',
    'country',
    'vehicle_type',
    'zone_id',
    'space_id',
    'space_code',
    'starts_at',
    'ends_at',
    'blocked_from',
    'blocked_until',
    'arrival_from',
    'no_show_after',
    'hold_expires_at',
    'confirmed_at',
    'checked_in_at',
    'completed_at',
    'cancelled_at',
    'expired_at',
    'cancel_reason',
    'currency',
    'quoted_amount',
    'tariff_version_id',
    'pricing',
    'notes',
    'idempotency_key',
    'created_by',
    'cancelled_by',
])]
class Reservation extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'blocked_from' => 'immutable_datetime',
            'blocked_until' => 'immutable_datetime',
            'arrival_from' => 'immutable_datetime',
            'no_show_after' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'quoted_amount' => 'integer',
            'pricing' => 'array',
        ];
    }
}
