<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Presentation\Http\Controllers;

use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Vehicles\Application\EndVehicleLink;
use App\Modules\Vehicles\Application\LinkCustomerToVehicle;
use App\Modules\Vehicles\Application\ListVehicleHistory;
use App\Modules\Vehicles\Domain\VehicleRelationship;
use App\Modules\Vehicles\Infrastructure\Persistence\VehicleEvent;
use App\Modules\Vehicles\Presentation\Http\Requests\VehicleLinkRequest;
use App\Modules\Vehicles\Presentation\Http\VehiclePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VehicleRelationsController
{
    use ResolvesActor;

    public function link(string $vehicle, VehicleLinkRequest $request, LinkCustomerToVehicle $linkCustomer, CustomerDirectory $customers, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $link = $linkCustomer->handle(
            actorUserId: $this->actorId($request),
            tenantId: $tenantId,
            vehicleId: $vehicle,
            customerId: strtolower((string) $request->validated('customer_id')),
            relationship: VehicleRelationship::from((string) $request->validated('relationship')),
            transferOwnership: $request->boolean('transfer_ownership'),
        );

        return ApiResponse::success([
            'link' => VehiclePayload::link($link, $customers->summaries($tenantId, [(string) $link->customer_id])),
        ], 201, $request);
    }

    public function unlink(Request $request, string $vehicle, string $link, EndVehicleLink $endLink, TenantContext $context): JsonResponse
    {
        $endLink->handle($this->actorId($request), (string) $context->id(), $vehicle, $link);

        return ApiResponse::success(null, 200, $request);
    }

    public function history(Request $request, string $vehicle, ListVehicleHistory $listHistory, TenantContext $context): JsonResponse
    {
        [$page, $perPage] = $this->pagination($request, 20, 100);
        $events = $listHistory->handle($this->actorId($request), (string) $context->id(), $vehicle, $page, $perPage);

        return ApiResponse::success([
            'items' => collect($events->items())->map(fn (VehicleEvent $event): array => VehiclePayload::event($event))->values()->all(),
            'meta' => PageMeta::from($events),
        ], 200, $request);
    }
}
