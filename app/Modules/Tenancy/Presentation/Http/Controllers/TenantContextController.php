<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presentation\Http\Controllers;

use App\Modules\Identity\Application\BuildAuthProfile;
use App\Modules\Identity\Domain\EmailNotVerified;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Application\EstablishTenantContext;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Tenancy\Presentation\Http\Requests\SelectTenantRequest;
use Illuminate\Http\JsonResponse;

final class TenantContextController
{
    public function store(
        SelectTenantRequest $request,
        EstablishTenantContext $tenantContext,
        BuildAuthProfile $profile,
        TenantContext $context,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerified;
        }

        $tenantContext->handle(
            $request,
            strictHeader: false,
            preferredTenantId: (string) $request->validated('tenant_id'),
        );

        return ApiResponse::success($profile->handle($user, $context->id()), 200, $request);
    }
}
