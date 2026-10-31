<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Infrastructure\Persistence;

use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'plate',
    'country',
    'type',
    'brand',
    'model',
    'color',
    'year',
    'is_electric',
    'status',
    'status_reason',
    'status_changed_at',
    'notes',
])]
class Vehicle extends Model
{
    use HasUlids;

    /**
     * @return HasMany<CustomerVehicle, $this>
     */
    public function activeLinks(): HasMany
    {
        return $this->hasMany(CustomerVehicle::class)->whereNull('ended_at')->orderBy('started_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
            'status' => VehicleStatus::class,
            'year' => 'integer',
            'is_electric' => 'boolean',
            'status_changed_at' => 'immutable_datetime',
        ];
    }
}
