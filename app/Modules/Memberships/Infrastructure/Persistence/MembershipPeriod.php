<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One billing period of a membership: what is owed for which days. It is
 * "due" until payments (0.9) mark it paid.
 */
#[Fillable(['tenant_id', 'membership_id', 'sequence', 'starts_on', 'ends_on', 'amount', 'currency', 'status', 'origin', 'created_by', 'idempotency_key'])]
class MembershipPeriod extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'amount' => 'integer',
        ];
    }
}
