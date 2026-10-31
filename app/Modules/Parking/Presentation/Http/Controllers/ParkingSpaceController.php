<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Controllers;

use App\Modules\Parking\Application\ChangeParkingSpaceStatus;
use App\Modules\Parking\Application\CreateParkingSpace;
use App\Modules\Parking\Application\CreateParkingSpaceRange;
use App\Modules\Parking\Application\DeleteParkingSpace;
use App\Modules\Parking\Application\ListParkingSpaces;
use App\Modules\Parking\Application\UpdateParkingSpace;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Domain\SpaceType;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Parking\Presentation\Http\ParkingPayload;
use App\Modules\Parking\Presentation\Http\Requests\ParkingSpaceRangeRequest;
use App\Modules\Parking\Presentation\Http\Requests\ParkingSpaceRequest;
use App\Modules\Parking\Presentation\Http\Requests\SpaceStatusRequest;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ParkingSpaceController
{
    use ResolvesActor;

    public function index(Request $request, string $lot, ListParkingSpaces $listParkingSpaces, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'zone_id' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== 'none' && (! is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $value) !== 1)) {
                    $fail('La zona no es válida.');
                }
            }],
            'status' => ['nullable', Rule::enum(SpaceStatus::class)],
            'type' => ['nullable', Rule::enum(SpaceType::class)],
            'search' => ['nullable', 'string', 'max:40'],
        ]);

        // The space map loads a whole lot at once, so this list allows larger pages.
        [$page, $perPage] = $this->pagination($request, 100, 500);

        $spaces = $listParkingSpaces->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            lotId: $lot,
            page: $page,
            perPage: $perPage,
            zoneId: isset($filters['zone_id']) ? strtolower((string) $filters['zone_id']) : null,
            status: isset($filters['status']) ? SpaceStatus::from((string) $filters['status']) : null,
            type: isset($filters['type']) ? SpaceType::from((string) $filters['type']) : null,
            search: $filters['search'] ?? null,
        );

        return ApiResponse::success([
            'items' => collect($spaces->items())->map(fn (ParkingSpace $space): array => ParkingPayload::space($space))->values()->all(),
            'meta' => PageMeta::from($spaces),
        ], 200, $request);
    }

    public function store(string $lot, ParkingSpaceRequest $request, CreateParkingSpace $createParkingSpace, TenantContext $context): JsonResponse
    {
        $space = $createParkingSpace->handle(
            $this->actorId($request),
            (string) $context->id(),
            $lot,
            (string) $request->validated('code'),
            $request->toData(),
        );

        return ApiResponse::success(['space' => ParkingPayload::space($space)], 201, $request);
    }

    public function bulk(string $lot, ParkingSpaceRangeRequest $request, CreateParkingSpaceRange $createParkingSpaceRange, TenantContext $context): JsonResponse
    {
        $created = $createParkingSpaceRange->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            lotId: $lot,
            prefix: (string) ($request->validated('prefix') ?? ''),
            from: (int) $request->validated('from'),
            to: (int) $request->validated('to'),
            padding: (int) ($request->validated('padding') ?? 0),
            data: $request->toData(),
        );

        return ApiResponse::success(['created' => $created], 201, $request);
    }

    public function update(string $lot, string $space, ParkingSpaceRequest $request, UpdateParkingSpace $updateParkingSpace, TenantContext $context): JsonResponse
    {
        $updated = $updateParkingSpace->handle(
            $this->actorId($request),
            (string) $context->id(),
            $lot,
            $space,
            (string) $request->validated('code'),
            $request->toData(),
        );

        return ApiResponse::success(['space' => ParkingPayload::space($updated)], 200, $request);
    }

    public function status(string $lot, string $space, SpaceStatusRequest $request, ChangeParkingSpaceStatus $changeParkingSpaceStatus, TenantContext $context): JsonResponse
    {
        $reason = $request->validated('reason');

        $updated = $changeParkingSpaceStatus->handle(
            $this->actorId($request),
            (string) $context->id(),
            $lot,
            $space,
            SpaceStatus::from((string) $request->validated('status')),
            is_string($reason) ? $reason : null,
        );

        return ApiResponse::success(['space' => ParkingPayload::space($updated)], 200, $request);
    }

    public function destroy(Request $request, string $lot, string $space, DeleteParkingSpace $deleteParkingSpace, TenantContext $context): JsonResponse
    {
        $deleteParkingSpace->handle($this->actorId($request), (string) $context->id(), $lot, $space);

        return ApiResponse::success(null, 200, $request);
    }
}
