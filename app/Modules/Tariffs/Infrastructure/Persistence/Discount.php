<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Infrastructure\Persistence;

use App\Modules\Tariffs\Domain\DiscountApplication;
use App\Modules\Tariffs\Domain\DiscountType;
use App\Modules\Tariffs\Domain\TariffStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'parking_lot_id',
    'code',
    'name',
    'type',
    'value',
    'currency',
    'application',
    'status',
    'vehicle_types',
    'days',
    'valid_from',
    'valid_until',
    'created_by',
])]
class Discount extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value' => 'integer',
            'application' => DiscountApplication::class,
            'status' => TariffStatus::class,
            'vehicle_types' => 'array',
            'days' => 'array',
        ];
    }
}
