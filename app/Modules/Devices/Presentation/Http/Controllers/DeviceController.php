<?php

declare(strict_types=1);

namespace App\Modules\Devices\Presentation\Http\Controllers;

use App\Modules\Devices\Application\ChangeDeviceStatus;
use App\Modules\Devices\Application\DeviceSummaries;
use App\Modules\Devices\Application\ListDevices;
use App\Modules\Devices\Application\RegisterDevice;
use App\Modules\Devices\Application\RemoveDevice;
use App\Modules\Devices\Application\UpdateDevice;
use App\Modules\Devices\Domain\DeviceStatus;
use App\Modules\Devices\Domain\DeviceType;
use App\Modules\Devices\Infrastructure\Persistence\Device;
use App\Modules\Devices\Presentation\Http\DevicePayload;
use App\Modules\Devices\Presentation\Http\Requests\DeviceRequest;
use App\Modules\Devices\Presentation\Http\Requests\DeviceStatusRequest;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DeviceController
{
    use ResolvesActor;

    public function __construct(private readonly DeviceSummaries $summaries) {}

    public function index(Request $request, ListDevices $listDevices, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'parking_lot_id' => ['nullable', 'ulid'],
            'status' => ['nullable', Rule::enum(DeviceStatus::class)],
            'type' => ['nullable', Rule::enum(DeviceType::class)],
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        [$page, $perPage] = $this->pagination($request, 25, 100);
        $tenantId = (string) $context->id();

        $result = $listDevices->handle(
            actorUserId: $this->actorId($request),
            tenantId: $tenantId,
            page: $page,
            perPage: $perPage,
            parkingLotId: isset($filters['parking_lot_id']) ? strtolower((string) $filters['parking_lot_id']) : null,
            status: isset($filters['status']) ? DeviceStatus::from((string) $filters['status']) : null,
            type: isset($filters['type']) ? DeviceType::from((string) $filters['type']) : null,
            search: $filters['search'] ?? null,
        );

        $devices = $result['devices']->items();
        $summaries = $this->summaries->for($tenantId, $devices);

        return ApiResponse::success([
            'items' => collect($devices)->map(fn (Device $device): array => DevicePayload::from($device, $summaries))->values()->all(),
            'meta' => PageMeta::from($result['devices']),
            'status_counts' => $result['status_counts'],
        ], 200, $request);
    }

    public function store(DeviceRequest $request, RegisterDevice $registerDevice, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $device = $registerDevice->handle($this->actorId($request), $tenantId, $request->toData());

        return ApiResponse::success([
            'device' => DevicePayload::from($device, $this->summaries->for($tenantId, [$device])),
        ], 201, $request);
    }

    public function update(string $device, DeviceRequest $request, UpdateDevice $updateDevice, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $updated = $updateDevice->handle($this->actorId($request), $tenantId, $device, $request->toData());

        return ApiResponse::success([
            'device' => DevicePayload::from($updated, $this->summaries->for($tenantId, [$updated])),
        ], 200, $request);
    }

    public function status(string $device, DeviceStatusRequest $request, ChangeDeviceStatus $changeDeviceStatus, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $updated = $changeDeviceStatus->handle(
            $this->actorId($request),
            $tenantId,
            $device,
            DeviceStatus::from((string) $request->validated('status')),
        );

        return ApiResponse::success([
            'device' => DevicePayload::from($updated, $this->summaries->for($tenantId, [$updated])),
        ], 200, $request);
    }

    public function destroy(Request $request, string $device, RemoveDevice $removeDevice, TenantContext $context): JsonResponse
    {
        $removeDevice->handle($this->actorId($request), (string) $context->id(), $device);

        return ApiResponse::success(null, 200, $request);
    }
}
