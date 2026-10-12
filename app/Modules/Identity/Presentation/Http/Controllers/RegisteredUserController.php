<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\BuildAuthProfile;
use App\Modules\Identity\Application\RegisterUser;
use App\Modules\Identity\Presentation\Http\Requests\RegisterUserRequest;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Application\EstablishTenantContext;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class RegisteredUserController
{
    public function store(
        RegisterUserRequest $request,
        RegisterUser $registerUser,
        EstablishTenantContext $tenantContext,
        BuildAuthProfile $profile,
        TenantContext $context,
    ): JsonResponse {
        $user = $registerUser->handle(
            name: (string) $request->validated('name'),
            email: (string) $request->validated('email'),
            password: (string) $request->validated('password'),
        );

        Auth::login($user);
        $request->session()->regenerate();
        $tenantContext->handle($request, strictHeader: false);

        return ApiResponse::success(
            $profile->handle($user, $context->id()),
            201,
            $request,
        );
    }
}
