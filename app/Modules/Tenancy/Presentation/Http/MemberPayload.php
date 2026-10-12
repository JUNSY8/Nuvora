<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presentation\Http;

use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;

final class MemberPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(TenantMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'status' => $membership->status->value,
            'user' => [
                'id' => $membership->user?->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
            ],
            'roles' => $membership->roles->map(fn ($role): array => [
                'key' => $role->key,
                'name' => $role->name,
            ])->values()->all(),
        ];
    }
}
