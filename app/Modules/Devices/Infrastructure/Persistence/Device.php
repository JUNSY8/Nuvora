<?php

declare(strict_types=1);

namespace App\Modules\Devices\Infrastructure\Persistence;

use App\Modules\Devices\Domain\DeviceStatus;
use App\Modules\Devices\Domain\DeviceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'parking_lot_id',
    'gate_id',
    'name',
    'code',
    'type',
    'vendor',
    'model',
    'serial_number',
    'ip_address',
    'firmware_version',
    'status',
    'last_seen_at',
    'metadata',
])]
class Device extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DeviceType::class,
            'status' => DeviceStatus::class,
            'last_seen_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
