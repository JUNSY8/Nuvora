<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Presentation\Http\Controllers;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Reservations\Application\ChangeReservation;
use App\Modules\Reservations\Application\CreateReservation;
use App\Modules\Reservations\Application\ListReservations;
use App\Modules\Reservations\Application\ReservationAvailability;
use App\Modules\Reservations\Application\ReservationLookup;
use App\Modules\Reservations\Application\ReservationPolicies;
use App\Modules\Reservations\Domain\ReservationPolicy;
use App\Modules\Reservations\Domain\ReservationStatus;
use App\Modules\Reservations\Presentation\Http\Requests\ReservationRequest;
use App\Modules\Reservations\Presentation\Http\ReservationPayload;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReservationController
{
    use ResolvesActor;

    public function index(Request $request, ListReservations $listReservations, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'lot_id' => ['nullable', 'ulid', 'required_with:from_date,to_date'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(ReservationStatus::class)],
            'search' => ['nullable', 'string', 'max:20'],
            'vehicle_id' => ['nullable', 'ulid'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        [$page, $perPage] = $this->pagination($request, 25, 100);
        $tenantId = (string) $context->id();

        $reservations = $listReservations->handle(
            $this->actorId($request),
            $tenantId,
            isset($filters['lot_id']) ? strtolower((string) $filters['lot_id']) : null,
            array_map(fn (mixed $status): ReservationStatus => ReservationStatus::from((string) $status), $filters['status'] ?? []),
            $filters['search'] ?? null,
            $filters['from_date'] ?? null,
            $filters['to_date'] ?? null,
            isset($filters['vehicle_id']) ? strtolower((string) $filters['vehicle_id']) : null,
            $page,
            $perPage,
        );

        return ApiResponse::success([
            'items' => ReservationPayload::many($tenantId, $reservations->items()),
            'meta' => PageMeta::from($reservations),
        ], 200, $request);
    }

    public function show(Request $request, string $reservation, ReservationLookup $lookup, TenantAuthorizer $authorizer, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $authorizer->assert($this->actorId($request), $tenantId, Permission::RESERVATIONS_VIEW);

        return ApiResponse::success(['reservation' => ReservationPayload::one($tenantId, $lookup->find($tenantId, $reservation))], 200, $request);
    }

    public function store(ReservationRequest $request, CreateReservation $createReservation, ParkingInfrastructureDirectory $parking, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $outcome = $createReservation->handle($this->actorId($request), $tenantId, $request->toData($parking, $context));

        return ApiResponse::success([
            'reservation' => ReservationPayload::one($tenantId, $outcome->reservation),
            'replayed' => $outcome->replayed,
        ], $outcome->replayed ? 200 : 201, $request);
    }

    public function confirm(Request $request, string $reservation, ChangeReservation $change, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $confirmed = $change->confirm($this->actorId($request), $tenantId, $reservation);

        return ApiResponse::success(['reservation' => ReservationPayload::one($tenantId, $confirmed)], 200, $request);
    }

    public function cancel(Request $request, string $reservation, ChangeReservation $change, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [
            'reason.required' => 'Indica el motivo de la cancelación.',
            'reason.min' => 'Describe el motivo con al menos 3 caracteres.',
        ]);
        $tenantId = (string) $context->id();
        $cancelled = $change->cancel($this->actorId($request), $tenantId, $reservation, (string) $validated['reason']);

        return ApiResponse::success(['reservation' => ReservationPayload::one($tenantId, $cancelled)], 200, $request);
    }

    public function availability(Request $request, ReservationAvailability $availability, ParkingInfrastructureDirectory $parking, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'lot_id' => ['required', 'ulid'],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $tenantId = (string) $context->id();
        $lotId = strtolower((string) $validated['lot_id']);
        $lot = $parking->lot($tenantId, $lotId)
            ?? throw ValidationException::withMessages(['lot_id' => 'El parqueadero no existe en esta empresa.']);

        $answer = $availability->handle(
            $this->actorId($request),
            $tenantId,
            $lotId,
            VehicleType::from((string) $validated['vehicle_type']),
            ReservationRequest::local((string) $validated['starts_at'], $lot['timezone']),
            ReservationRequest::local((string) $validated['ends_at'], $lot['timezone']),
        );

        return ApiResponse::success($answer, 200, $request);
    }

    public function policy(Request $request, ReservationPolicies $policies, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['lot_id' => ['required', 'ulid']]);
        $policy = $policies->show($this->actorId($request), (string) $context->id(), strtolower((string) $validated['lot_id']));

        return ApiResponse::success(['policy' => $policy->toArray()], 200, $request);
    }

    public function updatePolicy(Request $request, ReservationPolicies $policies, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'lot_id' => ['required', 'ulid'],
            'enabled' => ['required', 'boolean'],
            'buffer_minutes' => ['required', 'integer', 'between:0,240'],
            'hold_minutes' => ['required', 'integer', 'between:1,1440'],
            'arrival_tolerance_minutes' => ['required', 'integer', 'between:0,720'],
            'min_duration_minutes' => ['required', 'integer', 'between:15,1440'],
            'max_duration_minutes' => ['required', 'integer', 'between:15,44640'],
            'max_advance_days' => ['required', 'integer', 'between:1,365'],
            'within_operating_hours' => ['required', 'boolean'],
        ]);

        $policy = $policies->update($this->actorId($request), (string) $context->id(), strtolower((string) $validated['lot_id']), ReservationPolicy::create(
            (bool) $validated['enabled'],
            (int) $validated['buffer_minutes'],
            (int) $validated['hold_minutes'],
            (int) $validated['arrival_tolerance_minutes'],
            (int) $validated['min_duration_minutes'],
            (int) $validated['max_duration_minutes'],
            (int) $validated['max_advance_days'],
            (bool) $validated['within_operating_hours'],
        ));

        return ApiResponse::success(['policy' => $policy->toArray()], 200, $request);
    }
}
