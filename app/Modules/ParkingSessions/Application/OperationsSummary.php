<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Parking\Domain\Occupancy;
use App\Modules\ParkingSessions\Domain\ChargeStatus;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Figures for the operator screen of one lot. "Today" is the lot's local
 * day, not the server's. Nothing is stored: every figure is computed from
 * spaces and sessions.
 */
final class OperationsSummary
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly LiveUpdates $live,
    ) {}

    /**
     * @return array{lot: array<string, mixed>, occupancy: Occupancy, open: int, open_without_space: int, entries_today: int, exits_today: int, charged_today: int, unpriced_today: int, currency: string, cancelled_today: int, average_stay_minutes_today: int|null, oldest_open_entry_at: string|null, day_starts_at: string, version: string}
     */
    public function handle(string $actorUserId, string $tenantId, string $lotId): array
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_VIEW);

        $lot = $this->parking->lot($tenantId, $lotId)
            ?? throw ValidationException::withMessages(['lot_id' => 'El parqueadero no existe en esta empresa.']);

        $dayStart = CarbonImmutable::now($lot['timezone'])->startOfDay()->utc();
        $sessions = fn () => ParkingSession::query()->where('tenant_id', $tenantId)->where('parking_lot_id', $lot['id']);

        $open = $sessions()
            ->where('status', SessionStatus::Open->value)
            ->selectRaw('count(*) as total, sum(case when space_id is null then 1 else 0 end) as without_space, min(entry_at) as oldest')
            ->toBase()
            ->first();

        $closedToday = $sessions()
            ->where('status', SessionStatus::Closed->value)
            ->where('exit_at', '>=', $dayStart)
            ->selectRaw('count(*) as total, avg(duration_minutes) as average, sum(total_amount) as charged, sum(case when charge_status = ? then 1 else 0 end) as unpriced', [ChargeStatus::Unpriced->value])
            ->toBase()
            ->first();
        $average = $closedToday?->average;
        $oldest = $open?->oldest === null ? null : CarbonImmutable::parse((string) $open->oldest, 'UTC')->toIso8601String();

        return [
            'lot' => [
                'id' => $lot['id'],
                'name' => $lot['name'],
                'code' => $lot['code'],
                'timezone' => $lot['timezone'],
                'status' => $lot['status']->value,
            ],
            'occupancy' => $this->parking->occupancy($tenantId, $lot['id']),
            'open' => (int) ($open->total ?? 0),
            'open_without_space' => (int) ($open->without_space ?? 0),
            'entries_today' => $sessions()->where('entry_at', '>=', $dayStart)->where('status', '!=', SessionStatus::Cancelled->value)->count(),
            'exits_today' => (int) ($closedToday->total ?? 0),
            'charged_today' => (int) ($closedToday->charged ?? 0),
            'unpriced_today' => (int) ($closedToday->unpriced ?? 0),
            'currency' => $lot['currency'],
            'cancelled_today' => $sessions()->where('status', SessionStatus::Cancelled->value)->where('cancelled_at', '>=', $dayStart)->count(),
            'average_stay_minutes_today' => $average === null ? null : (int) round((float) $average),
            'oldest_open_entry_at' => $oldest,
            'day_starts_at' => $dayStart->toIso8601String(),
            'version' => $this->live->version(LiveUpdates::lot($tenantId, $lot['id'])),
        ];
    }
}
