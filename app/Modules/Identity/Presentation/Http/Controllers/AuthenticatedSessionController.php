<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Identity\Application\AuthenticateUser;
use App\Modules\Identity\Application\BuildAuthProfile;
use App\Modules\Identity\Domain\InvalidCredentials;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Identity\Presentation\Http\Requests\LoginRequest;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Application\EstablishTenantContext;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class AuthenticatedSessionController
{
    public function store(
        LoginRequest $request,
        AuthenticateUser $authenticateUser,
        EstablishTenantContext $tenantContext,
        BuildAuthProfile $profile,
        TenantContext $context,
    ): JsonResponse {
        $authenticated = $authenticateUser->handle(
            email: (string) $request->validated('email'),
            password: (string) $request->validated('password'),
            remember: (bool) $request->boolean('remember'),
        );

        if (! $authenticated) {
            throw new InvalidCredentials;
        }

        $request->session()->regenerate();
        $tenantContext->handle($request, strictHeader: false);

        $user = $request->user();

        if (! $user instanceof User) {
            throw new InvalidCredentials;
        }

        return ApiResponse::success($profile->handle($user, $context->id()), 200, $request);
    }

    public function destroy(Request $request, RecordAuditEvent $audit, TenantContext $context): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();
        $userId = is_string($userId) ? $userId : null;

        $audit->record(
            action: 'auth.logout',
            resourceType: 'user',
            resourceId: $userId,
            tenantId: $context->id(),
            actorUserId: $userId,
        );

        Log::info('logout', [
            'user_id' => $userId,
        ]);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $context->clear();

        return ApiResponse::success(status: 200, request: $request);
    }
}
