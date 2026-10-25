<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'number',
    'payable_type',
    'payable_id',
    'parking_lot_id',
    'customer_id',
    'description',
    'plate',
    'details',
    'method',
    'provider',
    'provider_reference',
    'status',
    'amount',
    'refunded_amount',
    'currency',
    'tendered_amount',
    'change_amount',
    'reference',
    'notes',
    'idempotency_key',
    'received_by',
    'received_at',
    'reconciliation_id',
])]
class Payment extends Model
{
    use HasUlids;

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class)->orderBy('refunded_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return BelongsTo<PaymentReconciliation, $this>
     */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(PaymentReconciliation::class, 'reconciliation_id');
    }

    /**
     * What this payment still counts towards its payable.
     */
    public function netAmount(): int
    {
        return $this->status->isCollected() ? $this->amount - $this->refunded_amount : 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payable_type' => PayableType::class,
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'details' => 'array',
            'amount' => 'integer',
            'refunded_amount' => 'integer',
            'tendered_amount' => 'integer',
            'change_amount' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }
}
