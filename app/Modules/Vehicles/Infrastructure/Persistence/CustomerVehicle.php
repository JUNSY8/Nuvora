<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Infrastructure\Persistence;

use App\Modules\Vehicles\Domain\VehicleRelationship;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'customer_id',
    'vehicle_id',
    'relationship',
    'started_at',
    'ended_at',
])]
class CustomerVehicle extends Model
{
    use HasUlids;

    protected $table = 'customer_vehicles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relationship' => VehicleRelationship::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
