<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A vehicle in a membership. Removing it closes the row instead of deleting
 * it, so the membership keeps its history.
 */
#[Fillable(['tenant_id', 'membership_id', 'vehicle_id', 'plate', 'added_at', 'removed_at', 'added_by'])]
class MembershipVehicle extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'added_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }
}
