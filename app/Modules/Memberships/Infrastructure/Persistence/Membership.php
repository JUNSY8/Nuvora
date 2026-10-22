<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Infrastructure\Persistence;

use App\Modules\Memberships\Domain\BillingPeriod;
use App\Modules\Memberships\Domain\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'plan_id',
    'customer_id',
    'code',
    'status',
    'billing_period',
    'price_amount',
    'currency',
    'grace_days',
    'starts_on',
    'anchor_on',
    'current_period_start',
    'current_period_end',
    'periods_count',
    'auto_renew',
    'assigned_lot_id',
    'assigned_space_id',
    'assigned_space_code',
    'status_reason',
    'ended_on',
    'notes',
    'created_by',
])]
class Membership extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<MembershipPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    /**
     * Vehicles currently in the membership.
     *
     * @return HasMany<MembershipVehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(MembershipVehicle::class)->whereNull('removed_at')->orderBy('added_at');
    }

    /**
     * @return HasMany<MembershipPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(MembershipPeriod::class)->orderByDesc('sequence');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'billing_period' => BillingPeriod::class,
            'price_amount' => 'integer',
            'grace_days' => 'integer',
            'starts_on' => 'immutable_date',
            'anchor_on' => 'immutable_date',
            'current_period_start' => 'immutable_date',
            'current_period_end' => 'immutable_date',
            'ended_on' => 'immutable_date',
            'periods_count' => 'integer',
            'auto_renew' => 'boolean',
        ];
    }
}
