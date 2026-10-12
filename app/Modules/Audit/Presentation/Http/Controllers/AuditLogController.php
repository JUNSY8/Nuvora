<?php

declare(strict_types=1);

namespace App\Modules\Audit\Presentation\Http\Controllers;

use App\Modules\Audit\Application\ListAuditEvents;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController
{
    public function index(Request $request, ListAuditEvents $listAuditEvents, TenantContext $context): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $page = max(1, (int) $request->query('page', 1));
        $logs = $listAuditEvents->handle((string) $user->id, (string) $context->id(), $page);

        return ApiResponse::success([
            'items' => collect($logs->items())->map(fn ($log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
                'actor_user_id' => $log->actor_user_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ], 200, $request);
    }
}
