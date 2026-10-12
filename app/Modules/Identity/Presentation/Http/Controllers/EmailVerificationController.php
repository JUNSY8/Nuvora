<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\VerifyEmailAddress;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EmailVerificationController
{
    public function verify(Request $request, string $id, string $hash, VerifyEmailAddress $verifyEmail): JsonResponse|RedirectResponse
    {
        $verifyEmail->handle($id, $hash);

        if ($request->expectsJson()) {
            return ApiResponse::success(['verified' => true], 200, $request);
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return redirect()->away($frontend.'/?verificado=1');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return ApiResponse::success([
            'message' => 'Si el correo aún no está verificado, enviamos un nuevo enlace.',
        ], 200, $request);
    }
}
