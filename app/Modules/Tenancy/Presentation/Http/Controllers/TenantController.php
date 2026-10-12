<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presentation\Http\Controllers;

use App\Modules\Identity\Application\BuildAuthProfile;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Application\CreateTenant;
use App\Modules\Tenancy\Application\EstablishTenantContext;
use App\Modules\Tenancy\Application\UpdateTenant;
use App\Modules\Tenancy\Infrastructure\Persistence\Tenant;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Tenancy\Presentation\Http\Requests\CreateTenantRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\UpdateTenantRequest;
use Illuminate\Http\JsonResponse;

final class TenantController
{
    public function store(
        CreateTenantRequest $request,
        CreateTenant $createTenant,
        EstablishTenantContext $tenantContext,
        BuildAuthProfile $profile,
        TenantContext $context,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $tenant = $createTenant->handle(
            user: $user,
            name: (string) $request->validated('name'),
            timezone: (string) $request->validated('timezone'),
        );

        $tenantContext->handle($request, strictHeader: false, preferredTenantId: (string) $tenant->id);

        return ApiResponse::success($profile->handle($user->fresh() ?? $user, $context->id()), 201, $request);
    }

    public function show(TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->whereKey($context->id())->firstOrFail();

        return ApiResponse::success([
            'tenant' => $this->payload($tenant),
        ]);
    }

    public function update(
        UpdateTenantRequest $request,
        UpdateTenant $updateTenant,
        TenantContext $context,
        BuildAuthProfile $profile,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $tenant = $updateTenant->handle(
            actorUserId: (string) $user->id,
            tenantId: (string) $context->id(),
            name: (string) $request->validated('name'),
            timezone: (string) $request->validated('timezone'),
        );

        return ApiResponse::success([
            'tenant' => $this->payload($tenant),
            'profile' => $profile->handle($user, $context->id()),
        ], 200, $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'timezone' => $tenant->timezone,
        ];
    }
}
