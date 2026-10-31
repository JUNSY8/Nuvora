<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Infrastructure\Persistence;

use App\Modules\Tariffs\Domain\TariffStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Identity of a tariff. Code, name and status mirror the current version
 * so lists can filter without reading versions; the rules live only in the
 * versions.
 */
#[Fillable([
    'tenant_id',
    'parking_lot_id',
    'currency',
    'code',
    'name',
    'status',
    'current_version',
    'created_by',
])]
class Tariff extends Model
{
    use HasUlids;

    /**
     * @return HasOne<TariffVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(TariffVersion::class)->ofMany('version', 'max');
    }

    /**
     * @return HasMany<TariffVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TariffVersion::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TariffStatus::class,
            'current_version' => 'integer',
        ];
    }
}
