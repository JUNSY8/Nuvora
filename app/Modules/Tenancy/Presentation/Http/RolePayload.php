<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presentation\Http;

use App\Modules\Tenancy\Infrastructure\Persistence\Role;

final class RolePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Role $role): array
    {
        return [
            'id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'is_system' => $role->is_system,
            'permissions' => $role->permissionGrants->pluck('permission')->values()->all(),
        ];
    }
}
