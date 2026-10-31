<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Presentation\Http\Controllers;

use App\Modules\Memberships\Application\ChangeMembership;
use App\Modules\Memberships\Application\ListMemberships;
use App\Modules\Memberships\Application\MembershipLookup;
use App\Modules\Memberships\Application\MembershipPlans;
use App\Modules\Memberships\Application\MembershipVehicles;
use App\Modules\Memberships\Application\SellMembership;
use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Memberships\Domain\PlanStatus;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipPlan;
use App\Modules\Memberships\Presentation\Http\MembershipPayload;
use App\Modules\Memberships\Presentation\Http\Requests\PlanRequest;
use App\Modules\Memberships\Presentation\Http\Requests\RenewRequest;
use App\Modules\Memberships\Presentation\Http\Requests\SubscriptionRequest;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MembershipController
{
    use ResolvesActor;

    public function plans(Request $request, MembershipPlans $plans, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', Rule::enum(PlanStatus::class)]]);
        $tenantId = (string) $context->id();
        $items = $plans->list($this->actorId($request), $tenantId, isset($validated['status']) ? PlanStatus::from((string) $validated['status']) : null);
        $sold = $plans->sold($tenantId);

        return ApiResponse::success([
            'items' => $items->map(fn (MembershipPlan $plan): array => MembershipPayload::plan($plan, $sold[(string) $plan->id] ?? 0))->values()->all(),
        ], 200, $request);
    }

    public function storePlan(PlanRequest $request, MembershipPlans $plans, TenantContext $context): JsonResponse
    {
        $plan = $plans->save($this->actorId($request), (string) $context->id(), null, $request->toData());

        return ApiResponse::success(['plan' => MembershipPayload::plan($plan, 0)], 201, $request);
    }

    public function updatePlan(string $plan, PlanRequest $request, MembershipPlans $plans, TenantContext $context): JsonResponse
    {
        $saved = $plans->save($this->actorId($request), (string) $context->id(), $plan, $request->toData());

        return ApiResponse::success(['plan' => MembershipPayload::plan($saved)], 200, $request);
    }

    public function planStatus(string $plan, Request $request, MembershipPlans $plans, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::enum(PlanStatus::class)]]);
        $saved = $plans->changeStatus($this->actorId($request), (string) $context->id(), $plan, PlanStatus::from((string) $validated['status']));

        return ApiResponse::success(['plan' => MembershipPayload::plan($saved)], 200, $request);
    }

    public function index(Request $request, ListMemberships $listMemberships, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(MembershipStatus::class)],
            'plan_id' => ['nullable', 'ulid'],
            'customer_id' => ['nullable', 'ulid'],
            'search' => ['nullable', 'string', 'max:20'],
        ]);

        [$page, $perPage] = $this->pagination($request, 25, 100);
        $tenantId = (string) $context->id();
        $memberships = $listMemberships->handle(
            $this->actorId($request),
            $tenantId,
            isset($filters['status']) ? MembershipStatus::from((string) $filters['status']) : null,
            isset($filters['plan_id']) ? strtolower((string) $filters['plan_id']) : null,
            isset($filters['customer_id']) ? strtolower((string) $filters['customer_id']) : null,
            $filters['search'] ?? null,
            $page,
            $perPage,
        );

        return ApiResponse::success([
            'items' => MembershipPayload::many($tenantId, $memberships->items()),
            'meta' => PageMeta::from($memberships),
        ], 200, $request);
    }

    public function show(Request $request, string $membership, ListMemberships $listMemberships, MembershipLookup $lookup, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $found = $listMemberships->show($this->actorId($request), $tenantId, $lookup->find($tenantId, $membership));

        return $this->respond($request, $found, 200, $context);
    }

    public function store(SubscriptionRequest $request, SellMembership $sell, TenantContext $context): JsonResponse
    {
        $membership = $sell->handle($this->actorId($request), (string) $context->id(), $request->toData());

        return $this->respond($request, $membership, 201, $context);
    }

    public function addVehicle(string $membership, Request $request, MembershipVehicles $vehicles, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'plate' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
        ]);

        $updated = $vehicles->add(
            $this->actorId($request),
            (string) $context->id(),
            $membership,
            (string) $validated['plate'],
            (string) $validated['country'],
            isset($validated['vehicle_type']) ? VehicleType::from((string) $validated['vehicle_type']) : null,
        );

        return $this->respond($request, $updated, 200, $context);
    }

    public function removeVehicle(Request $request, string $membership, string $vehicle, MembershipVehicles $vehicles, TenantContext $context): JsonResponse
    {
        $updated = $vehicles->remove($this->actorId($request), (string) $context->id(), $membership, strtolower($vehicle));

        return $this->respond($request, $updated, 200, $context);
    }

    public function renew(RenewRequest $request, string $membership, ChangeMembership $change, TenantContext $context): JsonResponse
    {
        return $this->respond($request, $change->renew($this->actorId($request), (string) $context->id(), $membership, $request->idempotencyKey()), 200, $context);
    }

    public function suspend(Request $request, string $membership, ChangeMembership $change, TenantContext $context): JsonResponse
    {
        $reason = $this->reason($request);

        return $this->respond($request, $change->suspend($this->actorId($request), (string) $context->id(), $membership, $reason), 200, $context);
    }

    public function reactivate(Request $request, string $membership, ChangeMembership $change, TenantContext $context): JsonResponse
    {
        return $this->respond($request, $change->reactivate($this->actorId($request), (string) $context->id(), $membership), 200, $context);
    }

    public function cancel(Request $request, string $membership, ChangeMembership $change, TenantContext $context): JsonResponse
    {
        $reason = $this->reason($request);

        return $this->respond($request, $change->cancel($this->actorId($request), (string) $context->id(), $membership, $reason), 200, $context);
    }

    public function autoRenew(Request $request, string $membership, ChangeMembership $change, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['auto_renew' => ['required', 'boolean']]);

        return $this->respond($request, $change->setAutoRenew($this->actorId($request), (string) $context->id(), $membership, (bool) $validated['auto_renew']), 200, $context);
    }

    private function reason(Request $request): string
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [
            'reason.required' => 'Indica el motivo.',
            'reason.min' => 'Describe el motivo con al menos 3 caracteres.',
        ]);

        return (string) $validated['reason'];
    }

    private function respond(Request $request, Membership $membership, int $status, TenantContext $context): JsonResponse
    {
        return ApiResponse::success(['membership' => MembershipPayload::one((string) $context->id(), $membership)], $status, $request);
    }
}
