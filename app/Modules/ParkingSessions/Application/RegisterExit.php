<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Application\ParkingSpaceAllocator;
use App\Modules\Parking\Domain\GateDirection;
use App\Modules\ParkingSessions\Domain\ChargeStatus;
use App\Modules\ParkingSessions\Domain\SessionRuleViolation;
use App\Modules\ParkingSessions\Domain\SessionSource;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\ParkingSessions\Domain\StayDuration;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Payments\Application\CollectPayment;
use App\Modules\Payments\Application\PaymentData;
use App\Modules\Payments\Domain\PayableType;
use App\Modules\Reservations\Application\ReservationDirectory;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Application\VehicleDirectory;
use App\Modules\Vehicles\Domain\VehicleEventType;
use Illuminate\Support\Facades\DB;

/**
 * Manual exit: closes the open session, records the stay length, prices it
 * and frees the space in one transaction. The session row is locked, so two
 * exits of the same stay cannot both succeed.
 *
 * A quote token prices the stay at the quoted instant while its hold lasts.
 * A payment sent with the exit is collected in the same transaction: if it
 * fails (not enough cash...), the exit fails too. Without a payment the
 * stay closes owing its charge, to be collected later.
 */
final class RegisterExit
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingSpaceAllocator $spaces,
        private readonly VehicleDirectory $vehicles,
        private readonly GateCheck $gates,
        private readonly SessionLookup $lookup,
        private readonly SessionPricing $pricing,
        private readonly ReservationDirectory $reservations,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
        private readonly CollectPayment $collect,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $sessionId, ?string $gateId, ?string $notes, ?string $idempotencyKey, ?string $discountId = null, ?ExitPayment $payment = null, ?string $quoteToken = null): SessionOutcome
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_CLOSE);

        if ($discountId !== null) {
            $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_DISCOUNT);
        }

        if ($payment !== null) {
            $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_CREATE);
        }

        if ($idempotencyKey !== null && ($previous = $this->lookup->byExitKey($tenantId, $idempotencyKey)) !== null) {
            if ((string) $previous->id !== $sessionId) {
                throw new SessionRuleViolation('La clave de idempotencia ya se usó para otra salida.', 'IDEMPOTENCY_KEY_REUSED', 409);
            }

            return new SessionOutcome($previous, true);
        }

        $replayed = false;
        $collected = null;

        $session = DB::transaction(function () use ($actorUserId, $tenantId, $sessionId, $gateId, $notes, $idempotencyKey, $discountId, $payment, $quoteToken, &$replayed, &$collected): ParkingSession {
            $session = $this->lookup->find($tenantId, $sessionId, lock: true);

            // A concurrent retry of the same exit waited on the lock: answer it with the closed session.
            if ($idempotencyKey !== null && $session->exit_idempotency_key === $idempotencyKey) {
                $replayed = true;

                return $session;
            }

            if (! $session->status->canTransitionTo(SessionStatus::Closed)) {
                throw self::notOpen($session);
            }

            $this->gates->assertUsable($tenantId, (string) $session->parking_lot_id, $gateId, GateDirection::Exit);

            $exitAt = now()->toImmutable();
            $minutes = StayDuration::minutes($session->entry_at, $exitAt);
            $pricedAt = $quoteToken === null ? null : QuoteToken::pricedAt($quoteToken, $tenantId, (string) $session->id, $discountId, $exitAt);
            $session->fill([
                ...$this->pricing->charge($tenantId, $session, $pricedAt ?? $exitAt, $discountId),
                'status' => SessionStatus::Closed,
                'exit_at' => $exitAt,
                'duration_minutes' => $minutes,
                'exit_source' => SessionSource::Manual,
                'exit_gate_id' => $gateId,
                'closed_by' => $actorUserId,
                'exit_idempotency_key' => $idempotencyKey,
                'notes' => self::appendNote($session->notes, $notes),
            ])->save();

            if ($session->space_id !== null) {
                $this->spaces->release($tenantId, (string) $session->parking_lot_id, (string) $session->space_id);
            }

            if ($session->reservation_id !== null) {
                $this->reservations->complete($tenantId, (string) $session->reservation_id, $actorUserId, $exitAt);
            }

            $this->vehicles->recordStayEvent($tenantId, (string) $session->vehicle_id, VehicleEventType::Exited, $actorUserId, [
                'session_id' => (string) $session->id,
                'parking_lot_id' => (string) $session->parking_lot_id,
                'space_code' => $session->space_code,
                'duration_minutes' => $minutes,
                'total_amount' => $session->total_amount,
                'currency' => $session->currency,
                'charge_status' => $session->charge_status?->value,
            ]);

            if ($payment !== null && $session->charge_status === ChargeStatus::Priced && (int) $session->total_amount > 0) {
                $collected = $this->collect->handle($actorUserId, $tenantId, new PaymentData(
                    payableType: PayableType::ParkingSession,
                    payableId: (string) $session->id,
                    method: $payment->method,
                    tendered: $payment->tendered,
                    reference: $payment->reference,
                ), $idempotencyKey === null ? null : 'exit-'.substr(hash('sha256', $idempotencyKey), 0, 40))->payment;
            }

            return $session;
        });

        if ($replayed) {
            return new SessionOutcome($session, true);
        }

        $this->audit->record(
            action: 'parking_session.closed',
            resourceType: 'parking_session',
            resourceId: (string) $session->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'parking_lot_id' => (string) $session->parking_lot_id,
                'plate' => $session->plate,
                'duration_minutes' => $session->duration_minutes,
                'exit_gate_id' => $session->exit_gate_id,
                'charge_status' => $session->charge_status?->value,
                'tariff_version_id' => $session->tariff_version_id,
                'discount_id' => $session->discount_id,
                'total_amount' => $session->total_amount,
                'currency' => $session->currency,
                'payment_id' => $collected?->id,
            ],
        );

        $this->live->touch(LiveUpdates::tenant($tenantId), LiveUpdates::lot($tenantId, (string) $session->parking_lot_id));

        return new SessionOutcome($session, false, payment: $collected === null ? null : [
            'id' => (string) $collected->id,
            'number' => $collected->number,
            'method' => $collected->method->value,
            'amount' => $collected->amount,
            'currency' => $collected->currency,
            'tendered_amount' => $collected->tendered_amount,
            'change_amount' => $collected->change_amount,
        ]);
    }

    public static function notOpen(ParkingSession $session): SessionRuleViolation
    {
        $message = $session->status === SessionStatus::Closed
            ? "La salida de {$session->plate} ya se registró."
            : "La sesión de {$session->plate} fue anulada.";

        return new SessionRuleViolation($message, 'SESSION_NOT_OPEN');
    }

    public static function appendNote(?string $current, ?string $note): ?string
    {
        $note = $note === null ? '' : trim($note);

        if ($note === '') {
            return $current;
        }

        return mb_substr($current === null || $current === '' ? $note : $current."\n".$note, 0, 500);
    }
}
