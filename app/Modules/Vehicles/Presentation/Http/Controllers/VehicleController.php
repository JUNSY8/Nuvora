<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Presentation\Http\Controllers;

use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Vehicles\Application\ChangeVehicleStatus;
use App\Modules\Vehicles\Application\ListVehicles;
use App\Modules\Vehicles\Application\PlateInspector;
use App\Modules\Vehicles\Application\RegisterVehicle;
use App\Modules\Vehicles\Application\ShowVehicle;
use App\Modules\Vehicles\Application\UpdateVehicle;
use App\Modules\Vehicles\Application\VehicleLinkSummaries;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Domain\VehicleType;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;
use App\Modules\Vehicles\Infrastructure\Plates\CountryPlateRules;
use App\Modules\Vehicles\Presentation\Http\Requests\VehicleRequest;
use App\Modules\Vehicles\Presentation\Http\Requests\VehicleStatusRequest;
use App\Modules\Vehicles\Presentation\Http\VehiclePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class VehicleController
{
    use ResolvesActor;

    public function __construct(
        private readonly VehicleLinkSummaries $summaries,
        private readonly CountryPlateRules $rules,
    ) {}

    public function index(Request $request, ListVehicles $listVehicles, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(VehicleStatus::class)],
            'type' => ['nullable', Rule::enum(VehicleType::class)],
            'customer_id' => ['nullable', 'ulid'],
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        [$page, $perPage] = $this->pagination($request, 25, 100);
        $tenantId = (string) $context->id();

        $result = $listVehicles->handle(
            actorUserId: $this->actorId($request),
            tenantId: $tenantId,
            page: $page,
            perPage: $perPage,
            status: isset($filters['status']) ? VehicleStatus::from((string) $filters['status']) : null,
            type: isset($filters['type']) ? VehicleType::from((string) $filters['type']) : null,
            customerId: isset($filters['customer_id']) ? strtolower((string) $filters['customer_id']) : null,
            search: $filters['search'] ?? null,
        );

        $vehicles = $result['vehicles']->items();
        $customers = $this->summaries->for($tenantId, $vehicles);

        return ApiResponse::success([
            'items' => collect($vehicles)->map(fn (Vehicle $vehicle): array => VehiclePayload::from($vehicle, $customers, $this->rules))->values()->all(),
            'meta' => PageMeta::from($result['vehicles']),
            'status_counts' => $result['status_counts'],
        ], 200, $request);
    }

    public function plateCheck(Request $request, PlateInspector $inspector, TenantContext $context): JsonResponse
    {
        $input = $request->validate([
            'plate' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
        ]);

        return ApiResponse::success($inspector->inspect(
            $this->actorId($request),
            (string) $context->id(),
            (string) $input['plate'],
            (string) $input['country'],
        ), 200, $request);
    }

    public function show(Request $request, string $vehicle, ShowVehicle $showVehicle, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $found = $showVehicle->handle($this->actorId($request), $tenantId, $vehicle);

        return $this->respond($request, $tenantId, $found, 200);
    }

    public function store(VehicleRequest $request, RegisterVehicle $registerVehicle, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $vehicle = $registerVehicle->handle(
            $this->actorId($request),
            $tenantId,
            $request->toData(),
            $request->customerId(),
            $request->relationship(),
        );

        return $this->respond($request, $tenantId, $vehicle->load('activeLinks'), 201);
    }

    public function update(string $vehicle, VehicleRequest $request, UpdateVehicle $updateVehicle, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $updated = $updateVehicle->handle($this->actorId($request), $tenantId, $vehicle, $request->toData());

        return $this->respond($request, $tenantId, $updated->load('activeLinks'), 200);
    }

    public function status(string $vehicle, VehicleStatusRequest $request, ChangeVehicleStatus $changeVehicleStatus, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $updated = $changeVehicleStatus->handle(
            $this->actorId($request),
            $tenantId,
            $vehicle,
            VehicleStatus::from((string) $request->validated('status')),
            $request->validated('reason'),
        );

        return $this->respond($request, $tenantId, $updated->load('activeLinks'), 200);
    }

    private function respond(Request $request, string $tenantId, Vehicle $vehicle, int $status): JsonResponse
    {
        return ApiResponse::success([
            'vehicle' => VehiclePayload::from($vehicle, $this->summaries->for($tenantId, [$vehicle]), $this->rules),
        ], $status, $request);
    }
}
