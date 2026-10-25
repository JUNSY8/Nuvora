<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Controllers;

use App\Modules\Payments\Application\CashReconciliation;
use App\Modules\Payments\Infrastructure\Persistence\PaymentReconciliation;
use App\Modules\Payments\Presentation\Http\PaymentPayload;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReconciliationController
{
    use ResolvesActor;

    public function index(Request $request, CashReconciliation $reconciliation, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['cashier_id' => ['nullable', 'ulid']]);
        [$page, $perPage] = $this->pagination($request, 25, 100);

        $closings = $reconciliation->list(
            $this->actorId($request),
            (string) $context->id(),
            isset($validated['cashier_id']) ? strtolower((string) $validated['cashier_id']) : null,
            $page,
            $perPage,
        );

        return ApiResponse::success([
            'items' => collect($closings->items())->map(fn (PaymentReconciliation $closing): array => PaymentPayload::reconciliation($closing))->values()->all(),
            'meta' => PageMeta::from($closings),
        ], 200, $request);
    }

    public function preview(Request $request, CashReconciliation $reconciliation, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['cashier_id' => ['nullable', 'ulid']]);
        $actorId = $this->actorId($request);
        $cashierId = isset($validated['cashier_id']) ? strtolower((string) $validated['cashier_id']) : $actorId;

        return ApiResponse::success([
            'cashier_id' => $cashierId,
            'currencies' => $reconciliation->preview($actorId, (string) $context->id(), $cashierId),
        ], 200, $request);
    }

    public function store(Request $request, CashReconciliation $reconciliation, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'cashier_id' => ['nullable', 'ulid'],
            'currency' => ['required', 'string', 'size:3'],
            'counted_cash' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'counted_cash.required' => 'Indica el efectivo contado.',
        ]);

        $actorId = $this->actorId($request);
        $closing = $reconciliation->close(
            $actorId,
            (string) $context->id(),
            isset($validated['cashier_id']) ? strtolower((string) $validated['cashier_id']) : $actorId,
            strtoupper((string) $validated['currency']),
            (int) $validated['counted_cash'],
            isset($validated['notes']) ? (string) $validated['notes'] : null,
        );

        return ApiResponse::success(['reconciliation' => PaymentPayload::reconciliation($closing)], 201, $request);
    }
}
