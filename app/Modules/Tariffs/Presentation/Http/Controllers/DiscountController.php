<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Presentation\Http\Controllers;

use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tariffs\Application\ChangeDiscountStatus;
use App\Modules\Tariffs\Application\ListDiscounts;
use App\Modules\Tariffs\Application\SaveDiscount;
use App\Modules\Tariffs\Domain\DiscountApplication;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Infrastructure\Persistence\Discount;
use App\Modules\Tariffs\Presentation\Http\Requests\DiscountRequest;
use App\Modules\Tariffs\Presentation\Http\TariffPayload;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DiscountController
{
    use ResolvesActor;

    public function index(Request $request, ListDiscounts $listDiscounts, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'lot_id' => ['nullable', 'ulid'],
            'application' => ['nullable', Rule::enum(DiscountApplication::class)],
            'status' => ['nullable', Rule::enum(TariffStatus::class)],
        ]);

        $discounts = $listDiscounts->handle(
            $this->actorId($request),
            (string) $context->id(),
            isset($filters['lot_id']) ? strtolower((string) $filters['lot_id']) : null,
            isset($filters['application']) ? DiscountApplication::from((string) $filters['application']) : null,
            isset($filters['status']) ? TariffStatus::from((string) $filters['status']) : null,
        );

        return ApiResponse::success([
            'items' => $discounts->map(fn (Discount $discount): array => TariffPayload::discount($discount))->values()->all(),
        ], 200, $request);
    }

    public function store(DiscountRequest $request, SaveDiscount $saveDiscount, TenantContext $context): JsonResponse
    {
        $discount = $saveDiscount->handle($this->actorId($request), (string) $context->id(), null, $request->toData());

        return ApiResponse::success(['discount' => TariffPayload::discount($discount)], 201, $request);
    }

    public function update(string $discount, DiscountRequest $request, SaveDiscount $saveDiscount, TenantContext $context): JsonResponse
    {
        $saved = $saveDiscount->handle($this->actorId($request), (string) $context->id(), $discount, $request->toData());

        return ApiResponse::success(['discount' => TariffPayload::discount($saved)], 200, $request);
    }

    public function status(string $discount, Request $request, ChangeDiscountStatus $changeStatus, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::enum(TariffStatus::class)]]);

        $updated = $changeStatus->handle($this->actorId($request), (string) $context->id(), $discount, TariffStatus::from((string) $validated['status']));

        return ApiResponse::success(['discount' => TariffPayload::discount($updated)], 200, $request);
    }
}
