<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Infrastructure\Persistence;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Vehicles\Domain\VehicleEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'vehicle_id',
    'type',
    'actor_user_id',
    'data',
    'occurred_at',
])]
class VehicleEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VehicleEventType::class,
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
