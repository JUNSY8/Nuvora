<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\BuildAuthProfile;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentUserController
{
    public function show(Request $request, BuildAuthProfile $profile, TenantContext $context): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::success(['user' => null, 'tenants' => [], 'current_tenant' => null], 200, $request);
        }

        return ApiResponse::success($profile->handle($user, $context->id()), 200, $request);
    }
}
