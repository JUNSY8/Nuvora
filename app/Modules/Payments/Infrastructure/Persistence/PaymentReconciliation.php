<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash closing: the movements a cashier received (and refunded) since
 * their last closing, what the drawer should hold and what was counted.
 */
#[Fillable([
    'tenant_id',
    'number',
    'cashier_id',
    'closed_by',
    'currency',
    'first_movement_at',
    'closed_at',
    'payments_count',
    'refunds_count',
    'totals',
    'expected_cash',
    'counted_cash',
    'difference',
    'notes',
])]
class PaymentReconciliation extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'payments_count' => 'integer',
            'refunds_count' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'difference' => 'integer',
            'first_movement_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
