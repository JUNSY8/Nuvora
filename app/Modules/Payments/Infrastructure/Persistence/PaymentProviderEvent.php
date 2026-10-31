<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A verified provider notification and what it did. The unique event key
 * per account makes a retried notification a no-op.
 */
#[Fillable(['tenant_id', 'provider_account_id', 'provider', 'event_key', 'event_type', 'reference', 'payment_id', 'status', 'outcome', 'payload', 'received_at', 'processed_at'])]
class PaymentProviderEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
