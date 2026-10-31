<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Infrastructure\Persistence;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\ParkingSessions\Domain\ChargeStatus;
use App\Modules\ParkingSessions\Domain\SessionSource;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'parking_lot_id',
    'vehicle_id',
    'customer_id',
    'space_id',
    'plate',
    'country',
    'vehicle_type',
    'space_code',
    'status',
    'entry_at',
    'exit_at',
    'cancelled_at',
    'duration_minutes',
    'entry_source',
    'exit_source',
    'entry_gate_id',
    'exit_gate_id',
    'opened_by',
    'closed_by',
    'entry_idempotency_key',
    'exit_idempotency_key',
    'cancel_reason',
    'notes',
    'charge_status',
    'currency',
    'tariff_id',
    'tariff_version_id',
    'discount_id',
    'charged_minutes',
    'subtotal_amount',
    'discount_amount',
    'total_amount',
    'pricing',
    'reservation_id',
    'membership_id',
])]
class ParkingSession extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'entry_source' => SessionSource::class,
            'exit_source' => SessionSource::class,
            'entry_at' => 'immutable_datetime',
            'exit_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'charge_status' => ChargeStatus::class,
            'charged_minutes' => 'integer',
            'subtotal_amount' => 'integer',
            'discount_amount' => 'integer',
            'total_amount' => 'integer',
            'pricing' => 'array',
        ];
    }
}
