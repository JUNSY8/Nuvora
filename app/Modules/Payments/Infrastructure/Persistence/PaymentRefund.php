<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\RefundStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'payment_id',
    'number',
    'amount',
    'currency',
    'method',
    'provider_reference',
    'reason',
    'idempotency_key',
    'refunded_by',
    'refunded_at',
    'reconciliation_id',
    'status',
    'provider',
    'failure_reason',
])]
class PaymentRefund extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => RefundStatus::class,
            'amount' => 'integer',
            'refunded_at' => 'immutable_datetime',
        ];
    }
}
