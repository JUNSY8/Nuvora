<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\RequestPasswordReset;
use App\Modules\Identity\Application\ResetUserPassword;
use App\Modules\Identity\Presentation\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Presentation\Http\Requests\ResetPasswordRequest;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PasswordResetController
{
    public function store(ForgotPasswordRequest $request, RequestPasswordReset $requestPasswordReset): JsonResponse
    {
        $requestPasswordReset->handle((string) $request->validated('email'));

        return ApiResponse::success([
            'message' => 'Si existe una cuenta con ese correo, enviamos un enlace para restablecer la contraseña.',
        ], 200, $request);
    }

    public function update(ResetPasswordRequest $request, ResetUserPassword $resetUserPassword): JsonResponse
    {
        $resetUserPassword->handle(
            email: (string) $request->validated('email'),
            password: (string) $request->validated('password'),
            passwordConfirmation: (string) $request->validated('password_confirmation'),
            token: (string) $request->validated('token'),
        );

        return ApiResponse::success([
            'message' => 'La contraseña fue actualizada. Ya puedes iniciar sesión.',
        ], 200, $request);
    }
}
