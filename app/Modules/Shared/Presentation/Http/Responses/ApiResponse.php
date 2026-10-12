<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(
        string $code,
        string $message,
        array $details = [],
        int $status = 400,
        ?Request $request = null,
    ): JsonResponse {
        $requestId = self::requestId($request);

        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details === [] ? (object) [] : $details,
            ],
            'request_id' => $requestId,
        ], $status)->header('X-Request-Id', $requestId);
    }

    public static function success(mixed $data = null, int $status = 200, ?Request $request = null): JsonResponse
    {
        $requestId = self::requestId($request);

        return response()->json([
            'success' => true,
            'data' => $data,
            'request_id' => $requestId,
        ], $status)->header('X-Request-Id', $requestId);
    }

    private static function requestId(?Request $request): string
    {
        $request ??= request();
        $requestId = $request->attributes->get('request_id');

        return is_string($requestId) && $requestId !== '' ? $requestId : '';
    }
}
