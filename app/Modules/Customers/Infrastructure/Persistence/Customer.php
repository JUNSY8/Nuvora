<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Persistence;

use App\Modules\Customers\Domain\CustomerStatus;
use App\Modules\Customers\Domain\CustomerType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'type',
    'name',
    'document_type',
    'document_number',
    'document_normalized',
    'email',
    'phone',
    'business_id',
    'notes',
    'status',
])]
class Customer extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'business_id');
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Customer::class, 'business_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
        ];
    }
}
