<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Infrastructure\Persistence;

use App\Modules\Memberships\Domain\BillingPeriod;
use App\Modules\Memberships\Domain\PlanCategory;
use App\Modules\Memberships\Domain\PlanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'code',
    'name',
    'category',
    'billing_period',
    'price_amount',
    'currency',
    'grace_days',
    'max_vehicles',
    'capacity',
    'parking_lot_ids',
    'zone_ids',
    'vehicle_types',
    'windows',
    'status',
    'description',
    'created_by',
])]
class MembershipPlan extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => PlanCategory::class,
            'billing_period' => BillingPeriod::class,
            'price_amount' => 'integer',
            'grace_days' => 'integer',
            'max_vehicles' => 'integer',
            'capacity' => 'integer',
            'parking_lot_ids' => 'array',
            'zone_ids' => 'array',
            'vehicle_types' => 'array',
            'windows' => 'array',
            'status' => PlanStatus::class,
        ];
    }
}
