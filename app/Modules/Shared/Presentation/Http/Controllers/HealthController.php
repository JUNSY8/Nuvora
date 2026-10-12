<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Http\Controllers;

use App\Modules\Shared\Application\Readiness\CheckReadiness;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function live(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
        ]);
    }

    public function ready(CheckReadiness $checkReadiness): JsonResponse
    {
        $report = $checkReadiness->handle();

        return ApiResponse::success(
            data: $report->toArray(),
            status: $report->isReady() ? 200 : 503,
        );
    }

    public function show(CheckReadiness $checkReadiness): JsonResponse
    {
        return $this->ready($checkReadiness);
    }
}
