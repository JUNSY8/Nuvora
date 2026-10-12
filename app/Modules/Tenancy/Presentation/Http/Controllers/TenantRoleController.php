<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presentation\Http\Controllers;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Application\CreateCustomRole;
use App\Modules\Tenancy\Application\ListTenantRoles;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Tenancy\Presentation\Http\Requests\CreateRoleRequest;
use App\Modules\Tenancy\Presentation\Http\RolePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantRoleController
{
    public function index(Request $request, ListTenantRoles $listTenantRoles, TenantContext $context): JsonResponse
    {
        $roles = $listTenantRoles->handle($this->actorId($request), (string) $context->id());

        return ApiResponse::success([
            'roles' => $roles->map(fn ($role): array => RolePayload::from($role))->values()->all(),
        ], 200, $request);
    }

    public function store(CreateRoleRequest $request, CreateCustomRole $createCustomRole, TenantContext $context): JsonResponse
    {
        /** @var list<string> $permissions */
        $permissions = array_values($request->validated('permissions'));

        $role = $createCustomRole->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            name: (string) $request->validated('name'),
            permissions: $permissions,
        );

        return ApiResponse::success([
            'role' => RolePayload::from($role),
        ], 201, $request);
    }

    private function actorId(Request $request): string
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return (string) $user->id;
    }
}
