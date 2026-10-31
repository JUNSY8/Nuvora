<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Controllers;

use App\Modules\Parking\Application\CreateParkingLot;
use App\Modules\Parking\Application\DeleteParkingLot;
use App\Modules\Parking\Application\ListParkingLots;
use App\Modules\Parking\Application\ReplaceOperatingHours;
use App\Modules\Parking\Application\ShowParkingLot;
use App\Modules\Parking\Application\UpdateParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Presentation\Http\ParkingPayload;
use App\Modules\Parking\Presentation\Http\Requests\OperatingHoursRequest;
use App\Modules\Parking\Presentation\Http\Requests\ParkingLotRequest;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParkingLotController
{
    use ResolvesActor;

    public function index(Request $request, ListParkingLots $listParkingLots, TenantContext $context): JsonResponse
    {
        [$page, $perPage] = $this->pagination($request, 25, 100);
        $search = $request->query('search');

        $result = $listParkingLots->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            page: $page,
            perPage: $perPage,
            search: is_string($search) ? $search : null,
        );

        return ApiResponse::success([
            'items' => collect($result['lots']->items())
                ->map(fn (ParkingLot $lot): array => ParkingPayload::lot($lot, $result['occupancy'][(string) $lot->id] ?? null))
                ->values()
                ->all(),
            'meta' => PageMeta::from($result['lots']),
        ], 200, $request);
    }

    public function show(Request $request, string $lot, ShowParkingLot $showParkingLot, TenantContext $context): JsonResponse
    {
        $result = $showParkingLot->handle($this->actorId($request), (string) $context->id(), $lot);

        return ApiResponse::success([
            'lot' => ParkingPayload::lot($result['lot'], $result['occupancy']),
        ], 200, $request);
    }

    public function store(ParkingLotRequest $request, CreateParkingLot $createParkingLot, ShowParkingLot $showParkingLot, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $lot = $createParkingLot->handle($this->actorId($request), $tenantId, $request->toData());
        $result = $showParkingLot->handle($this->actorId($request), $tenantId, (string) $lot->id);

        return ApiResponse::success([
            'lot' => ParkingPayload::lot($result['lot'], $result['occupancy']),
        ], 201, $request);
    }

    public function update(string $lot, ParkingLotRequest $request, UpdateParkingLot $updateParkingLot, ShowParkingLot $showParkingLot, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $updateParkingLot->handle($this->actorId($request), $tenantId, $lot, $request->toData());
        $result = $showParkingLot->handle($this->actorId($request), $tenantId, $lot);

        return ApiResponse::success([
            'lot' => ParkingPayload::lot($result['lot'], $result['occupancy']),
        ], 200, $request);
    }

    public function destroy(Request $request, string $lot, DeleteParkingLot $deleteParkingLot, TenantContext $context): JsonResponse
    {
        $deleteParkingLot->handle($this->actorId($request), (string) $context->id(), $lot);

        return ApiResponse::success(null, 200, $request);
    }

    public function hours(string $lot, OperatingHoursRequest $request, ReplaceOperatingHours $replaceOperatingHours, TenantContext $context): JsonResponse
    {
        /** @var list<array{day_of_week: int, mode: string, opens_at?: string|null, closes_at?: string|null}> $days */
        $days = array_values($request->validated('days'));

        $updated = $replaceOperatingHours->handle($this->actorId($request), (string) $context->id(), $lot, $days);

        return ApiResponse::success([
            'operating_hours' => $updated->operatingHours->map(fn ($hour): array => ParkingPayload::hour($hour))->values()->all(),
        ], 200, $request);
    }
}
