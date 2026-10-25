<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Presentation\Http\Controllers;

use App\Modules\ParkingSessions\Application\CancelSession;
use App\Modules\ParkingSessions\Application\ListParkingSessions;
use App\Modules\ParkingSessions\Application\MoveSession;
use App\Modules\ParkingSessions\Application\OperationsSummary;
use App\Modules\ParkingSessions\Application\QuoteSession;
use App\Modules\ParkingSessions\Application\RegisterEntry;
use App\Modules\ParkingSessions\Application\RegisterExit;
use App\Modules\ParkingSessions\Application\SessionFilters;
use App\Modules\ParkingSessions\Application\SessionOutcome;
use App\Modules\ParkingSessions\Application\SessionReferences;
use App\Modules\ParkingSessions\Application\ShowParkingSession;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\ParkingSessions\Presentation\Http\Requests\CancelSessionRequest;
use App\Modules\ParkingSessions\Presentation\Http\Requests\EntryRequest;
use App\Modules\ParkingSessions\Presentation\Http\Requests\ExitRequest;
use App\Modules\ParkingSessions\Presentation\Http\Requests\MoveSessionRequest;
use App\Modules\ParkingSessions\Presentation\Http\SessionPayload;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ParkingSessionController
{
    use ResolvesActor;

    public function index(Request $request, ListParkingSessions $listSessions, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'lot_id' => ['nullable', 'ulid', 'required_with:from_date,to_date'],
            'status' => ['nullable', Rule::enum(SessionStatus::class)],
            'search' => ['nullable', 'string', 'max:20'],
            'vehicle_id' => ['nullable', 'ulid'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'unpaid' => ['nullable', 'boolean'],
        ]);

        [$page, $perPage] = $this->pagination($request, 25, 100);
        $tenantId = (string) $context->id();

        $sessions = $listSessions->handle($this->actorId($request), $tenantId, new SessionFilters(
            lotId: isset($filters['lot_id']) ? strtolower((string) $filters['lot_id']) : null,
            status: isset($filters['status']) ? SessionStatus::from((string) $filters['status']) : null,
            search: $filters['search'] ?? null,
            vehicleId: isset($filters['vehicle_id']) ? strtolower((string) $filters['vehicle_id']) : null,
            fromDate: $filters['from_date'] ?? null,
            toDate: $filters['to_date'] ?? null,
            unpaid: (bool) ($filters['unpaid'] ?? false),
        ), $page, $perPage);

        $refs = SessionReferences::for($tenantId, $sessions->items());

        return ApiResponse::success([
            'items' => collect($sessions->items())->map(fn (ParkingSession $session): array => SessionPayload::session($session, $refs))->values()->all(),
            'meta' => PageMeta::from($sessions),
        ], 200, $request);
    }

    public function summary(Request $request, OperationsSummary $operationsSummary, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['lot_id' => ['required', 'ulid']]);

        $summary = $operationsSummary->handle($this->actorId($request), (string) $context->id(), strtolower((string) $validated['lot_id']));

        return ApiResponse::success(SessionPayload::summary($summary), 200, $request);
    }

    public function show(Request $request, string $session, ShowParkingSession $showSession, TenantContext $context): JsonResponse
    {
        $found = $showSession->handle($this->actorId($request), (string) $context->id(), $session);

        return $this->respond($request, new SessionOutcome($found, false), 200, $context);
    }

    public function quote(Request $request, string $session, QuoteSession $quoteSession, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['discount_id' => ['nullable', 'ulid']]);

        $quote = $quoteSession->handle(
            $this->actorId($request),
            (string) $context->id(),
            $session,
            isset($validated['discount_id']) ? strtolower((string) $validated['discount_id']) : null,
        );

        return ApiResponse::success(['quote' => $quote], 200, $request);
    }

    public function entry(EntryRequest $request, RegisterEntry $registerEntry, TenantContext $context): JsonResponse
    {
        $outcome = $registerEntry->handle($this->actorId($request), (string) $context->id(), $request->toData());

        return $this->respond($request, $outcome, 201, $context);
    }

    public function exit(string $session, ExitRequest $request, RegisterExit $registerExit, TenantContext $context): JsonResponse
    {
        $outcome = $registerExit->handle(
            $this->actorId($request),
            (string) $context->id(),
            $session,
            $request->gateId(),
            $request->notes(),
            $request->idempotencyKey(),
            $request->discountId(),
            $request->payment(),
            $request->quoteToken(),
        );

        return $this->respond($request, $outcome, 200, $context);
    }

    public function cancel(string $session, CancelSessionRequest $request, CancelSession $cancelSession, TenantContext $context): JsonResponse
    {
        $cancelled = $cancelSession->handle($this->actorId($request), (string) $context->id(), $session, (string) $request->validated('reason'));

        return $this->respond($request, new SessionOutcome($cancelled, false), 200, $context);
    }

    public function move(string $session, MoveSessionRequest $request, MoveSession $moveSession, TenantContext $context): JsonResponse
    {
        $moved = $moveSession->handle($this->actorId($request), (string) $context->id(), $session, strtolower((string) $request->validated('space_id')));

        return $this->respond($request, new SessionOutcome($moved, false), 200, $context);
    }

    private function respond(Request $request, SessionOutcome $outcome, int $status, TenantContext $context): JsonResponse
    {
        $session = $outcome->session->loadMissing(['openedBy:id,name', 'closedBy:id,name']);
        $refs = SessionReferences::for((string) $context->id(), [$session]);

        return ApiResponse::success([
            'session' => SessionPayload::session($session, $refs),
            'replayed' => $outcome->replayed,
            'notices' => $outcome->notices,
            'payment' => $outcome->payment,
        ], $outcome->replayed ? 200 : $status, $request);
    }
}
