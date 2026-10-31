<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Controllers;

use App\Modules\Parking\Application\DeleteParkingGate;
use App\Modules\Parking\Application\ListParkingGates;
use App\Modules\Parking\Application\SaveParkingGate;
use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\GateStatus;
use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Parking\Presentation\Http\ParkingPayload;
use App\Modules\Parking\Presentation\Http\Requests\ParkingGateRequest;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ParkingGateController
{
    use ResolvesActor;

    public function index(Request $request, string $lot, ListParkingGates $listParkingGates, TenantContext $context): JsonResponse
    {
        $gates = $listParkingGates->handle($this->actorId($request), (string) $context->id(), $lot);

        return ApiResponse::success([
            'gates' => $gates->map(fn (ParkingGate $gate): array => ParkingPayload::gate($gate))->values()->all(),
        ], 200, $request);
    }

    public function store(string $lot, ParkingGateRequest $request, SaveParkingGate $saveParkingGate, TenantContext $context): JsonResponse
    {
        return $this->save($request, $saveParkingGate, $context, $lot, null, 201);
    }

    public function update(string $lot, string $gate, ParkingGateRequest $request, SaveParkingGate $saveParkingGate, TenantContext $context): JsonResponse
    {
        return $this->save($request, $saveParkingGate, $context, $lot, $gate, 200);
    }

    public function destroy(Request $request, string $lot, string $gate, DeleteParkingGate $deleteParkingGate, TenantContext $context): JsonResponse
    {
        $deleteParkingGate->handle($this->actorId($request), (string) $context->id(), $lot, $gate);

        return ApiResponse::success(null, 200, $request);
    }

    private function save(ParkingGateRequest $request, SaveParkingGate $saveParkingGate, TenantContext $context, string $lot, ?string $gate, int $status): JsonResponse
    {
        $saved = $saveParkingGate->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            lotId: $lot,
            gateId: $gate,
            name: (string) $request->validated('name'),
            code: (string) $request->validated('code'),
            direction: GateDirection::from((string) $request->validated('direction')),
            status: GateStatus::from((string) $request->validated('status')),
        );

        return ApiResponse::success(['gate' => ParkingPayload::gate($saved)], $status, $request);
    }
}
