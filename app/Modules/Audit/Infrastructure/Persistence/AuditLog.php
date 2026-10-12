<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'actor_user_id',
    'action',
    'resource_type',
    'resource_id',
    'ip_address',
    'user_agent',
    'properties',
    'request_id',
])]
class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
