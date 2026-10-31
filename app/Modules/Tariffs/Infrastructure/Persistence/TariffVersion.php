<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Infrastructure\Persistence;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Tariffs\Domain\TariffStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable: a change to a tariff inserts a new version instead of
 * updating this row.
 */
#[Fillable([
    'tenant_id',
    'tariff_id',
    'parking_lot_id',
    'version',
    'effective_from',
    'code',
    'name',
    'status',
    'priority',
    'vehicle_types',
    'zone_id',
    'windows',
    'grace_minutes',
    'brackets',
    'step_minutes',
    'step_amount',
    'daily_max_amount',
    'change_note',
    'created_by',
])]
class TariffVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'immutable_datetime',
            'status' => TariffStatus::class,
            'priority' => 'integer',
            'vehicle_types' => 'array',
            'windows' => 'array',
            'grace_minutes' => 'integer',
            'brackets' => 'array',
            'step_minutes' => 'integer',
            'step_amount' => 'integer',
            'daily_max_amount' => 'integer',
        ];
    }
}
