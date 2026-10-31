<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Http\Controllers;

use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LiveUpdatesController
{
    /**
     * Change markers only, never data: a screen that sees a new version
     * reloads through the regular, permission-checked endpoints. Channels
     * are built from the resolved tenant, so another tenant's lot id only
     * reads an empty channel.
     */
    public function show(Request $request, LiveUpdates $live, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'lot_id' => ['nullable', 'ulid'],
        ]);

        $tenantId = (string) $context->id();
        $lotId = isset($validated['lot_id']) ? strtolower((string) $validated['lot_id']) : null;

        return ApiResponse::success([
            'tenant' => $live->version(LiveUpdates::tenant($tenantId)),
            'lot' => $lotId === null ? null : $live->version(LiveUpdates::lot($tenantId, $lotId)),
        ], 200, $request);
    }
}
