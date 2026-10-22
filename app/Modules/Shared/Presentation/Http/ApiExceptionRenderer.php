<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Http;

use App\Modules\Customers\Domain\CustomerRuleViolation;
use App\Modules\Devices\Domain\DeviceRuleViolation;
use App\Modules\Identity\Domain\EmailNotVerified;
use App\Modules\Identity\Domain\InvalidCredentials;
use App\Modules\Identity\Domain\PasswordResetThrottled;
use App\Modules\Memberships\Domain\MembershipRuleViolation as ParkingMembershipRuleViolation;
use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\ParkingSessions\Domain\SessionRuleViolation;
use App\Modules\Reservations\Domain\ReservationRuleViolation;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tariffs\Domain\TariffRuleViolation;
use App\Modules\Tenancy\Domain\MembershipRuleViolation;
use App\Modules\Tenancy\Domain\PermissionDenied;
use App\Modules\Tenancy\Domain\TenantContextMissing;
use App\Modules\Tenancy\Domain\TenantNotAccessible;
use App\Modules\Vehicles\Domain\VehicleRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (InvalidCredentials $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'INVALID_CREDENTIALS',
                message: 'Las credenciales no son válidas.',
                status: 401,
                request: $request,
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'VALIDATION_FAILED',
                message: 'Los datos enviados no son válidos.',
                details: $exception->errors(),
                status: $exception->status,
                request: $request,
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'UNAUTHENTICATED',
                message: 'Debes iniciar sesión para continuar.',
                status: 401,
                request: $request,
            );
        });

        $exceptions->render(function (EmailNotVerified $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'EMAIL_NOT_VERIFIED',
                message: 'Debes verificar tu correo antes de continuar.',
                status: 403,
                request: $request,
            );
        });

        $exceptions->render(function (PasswordResetThrottled $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'TOO_MANY_ATTEMPTS',
                message: 'Demasiados intentos. Espera un momento e inténtalo de nuevo.',
                status: 429,
                request: $request,
            );
        });

        $exceptions->render(function (TenantContextMissing $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'TENANT_REQUIRED',
                message: 'Selecciona una empresa para continuar.',
                status: 409,
                request: $request,
            );
        });

        $exceptions->render(function (TenantNotAccessible $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'NOT_FOUND',
                message: 'El recurso solicitado no existe.',
                status: 404,
                request: $request,
            );
        });

        $exceptions->render(function (PermissionDenied $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'FORBIDDEN',
                message: 'No tienes permiso para realizar esta acción.',
                status: 403,
                request: $request,
            );
        });

        $exceptions->render(function (MembershipRuleViolation $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'MEMBERSHIP_RULE',
                message: $exception->getMessage(),
                status: 422,
                request: $request,
            );
        });

        $exceptions->render(function (ParkingRuleViolation|DeviceRuleViolation|CustomerRuleViolation|VehicleRuleViolation $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                status: 422,
                request: $request,
            );
        });

        $exceptions->render(function (SessionRuleViolation|TariffRuleViolation|ReservationRuleViolation|ParkingMembershipRuleViolation $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                status: $exception->status,
                request: $request,
            );
        });

        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'INVALID_SIGNATURE',
                message: 'El enlace no es válido o expiró.',
                status: 403,
                request: $request,
            );
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'FORBIDDEN',
                message: 'No tienes permiso para realizar esta acción.',
                status: 403,
                request: $request,
            );
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'NOT_FOUND',
                message: 'El recurso solicitado no existe.',
                status: 404,
                request: $request,
            );
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            if ($exception instanceof InvalidSignatureException) {
                return ApiResponse::error(
                    code: 'INVALID_SIGNATURE',
                    message: 'El enlace no es válido o expiró.',
                    status: 403,
                    request: $request,
                );
            }

            $status = $exception->getStatusCode();
            [$code, $message] = match ($status) {
                419 => ['CSRF_TOKEN_MISMATCH', 'La sesión expiró. Recarga la página e inténtalo de nuevo.'],
                429 => ['TOO_MANY_ATTEMPTS', 'Demasiados intentos. Espera un momento e inténtalo de nuevo.'],
                default => ['HTTP_ERROR', 'No fue posible completar la solicitud.'],
            };

            return ApiResponse::error(
                code: $code,
                message: $message,
                status: $status,
                request: $request,
            );
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                code: 'INTERNAL_ERROR',
                message: 'Ocurrió un error interno.',
                status: 500,
                request: $request,
            );
        });
    }

    private static function wantsJson(Request $request): bool
    {
        return $request->is('api/*')
            || $request->is('health')
            || $request->is('health/*')
            || $request->expectsJson();
    }
}
