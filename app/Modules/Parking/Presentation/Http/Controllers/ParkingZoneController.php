<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Controllers;

use App\Modules\Parking\Application\CreateParkingZone;
use App\Modules\Parking\Application\DeleteParkingZone;
use App\Modules\Parking\Application\ListParkingZones;
use App\Modules\Parking\Application\UpdateParkingZone;
use App\Modules\Parking\Infrastructure\Persistence\ParkingZone;
use App\Modules\Parking\Presentation\Http\ParkingPayload;
use App\Modules\Parking\Presentation\Http\Requests\ParkingZoneRequest;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParkingZoneController
{
    use ResolvesActor;

    public function index(Request $request, string $lot, ListParkingZones $listParkingZones, TenantContext $context): JsonResponse
    {
        $zones = $listParkingZones->handle($this->actorId($request), (string) $context->id(), $lot);

        return ApiResponse::success([
            'zones' => $zones->map(fn (ParkingZone $zone): array => ParkingPayload::zone($zone))->values()->all(),
        ], 200, $request);
    }

    public function store(string $lot, ParkingZoneRequest $request, CreateParkingZone $createParkingZone, TenantContext $context): JsonResponse
    {
        $zone = $createParkingZone->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            lotId: $lot,
            name: (string) $request->validated('name'),
            code: (string) $request->validated('code'),
            description: $request->validated('description'),
            isActive: (bool) $request->validated('is_active'),
            sortOrder: (int) ($request->validated('sort_order') ?? 0),
        );

        return ApiResponse::success(['zone' => ParkingPayload::zone($zone)], 201, $request);
    }

    public function update(string $lot, string $zone, ParkingZoneRequest $request, UpdateParkingZone $updateParkingZone, TenantContext $context): JsonResponse
    {
        $updated = $updateParkingZone->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            lotId: $lot,
            zoneId: $zone,
            name: (string) $request->validated('name'),
            code: (string) $request->validated('code'),
            description: $request->validated('description'),
            isActive: (bool) $request->validated('is_active'),
            sortOrder: (int) ($request->validated('sort_order') ?? 0),
        );

        return ApiResponse::success(['zone' => ParkingPayload::zone($updated)], 200, $request);
    }

    public function destroy(Request $request, string $lot, string $zone, DeleteParkingZone $deleteParkingZone, TenantContext $context): JsonResponse
    {
        $deleteParkingZone->handle($this->actorId($request), (string) $context->id(), $lot, $zone);

        return ApiResponse::success(null, 200, $request);
    }
}
