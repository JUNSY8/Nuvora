<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\ParkingSessions\Domain\ChargeStatus;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\Payments\Application\Payable;
use App\Modules\Payments\Application\PayableSource;
use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentBalance;

/**
 * Lets Payments collect the charge of a closed stay. Only a priced stay with
 * a total above zero owes money; the session keeps no payment columns, its
 * balance is read from Payments when shown.
 */
final class SessionPayables implements PayableSource
{
    public function __construct(
        private readonly SessionLookup $lookup,
        private readonly ParkingInfrastructureDirectory $parking,
    ) {}

    public function type(): PayableType
    {
        return PayableType::ParkingSession;
    }

    public function lock(string $tenantId, string $payableId): Payable
    {
        $session = $this->lookup->find($tenantId, $payableId, lock: true);
        $lot = $this->parking->lotSummaries($tenantId, [(string) $session->parking_lot_id])[(string) $session->parking_lot_id] ?? null;
        $pricing = $session->pricing ?? [];

        $reason = match (true) {
            $session->status === SessionStatus::Open => 'La estancia sigue abierta: se cobra al registrar la salida.',
            $session->status === SessionStatus::Cancelled => 'La sesión fue anulada y no tiene cobro.',
            $session->charge_status === ChargeStatus::Covered => 'La estancia la cubre una mensualidad.',
            $session->charge_status === ChargeStatus::Unpriced => 'La salida se registró sin tarifa y no tiene valor a cobrar.',
            (int) $session->total_amount <= 0 => 'La estancia no tiene valor a pagar.',
            default => null,
        };

        return new Payable(
            type: PayableType::ParkingSession,
            id: (string) $session->id,
            amountDue: (int) ($session->total_amount ?? 0),
            currency: $session->currency,
            lotId: (string) $session->parking_lot_id,
            customerId: $session->customer_id === null ? null : (string) $session->customer_id,
            description: 'Estancia '.$session->plate.($lot === null ? '' : ' · '.$lot['name']),
            plate: $session->plate,
            details: [
                'kind' => 'parking_session',
                'session_id' => (string) $session->id,
                'parking_lot' => $lot['name'] ?? null,
                'plate' => $session->plate,
                'space_code' => $session->space_code,
                'entry_at' => $session->entry_at->toIso8601String(),
                'exit_at' => $session->exit_at?->toIso8601String(),
                'duration_minutes' => $session->duration_minutes,
                'charged_minutes' => $session->charged_minutes,
                'tariff' => $pricing['tariff']['name'] ?? null,
                'lines' => array_values(array_map(
                    fn (array $line): array => ['label' => (string) ($line['label'] ?? ''), 'amount' => (int) ($line['amount'] ?? 0)],
                    $pricing['lines'] ?? [],
                )),
            ],
            notPayableReason: $reason,
        );
    }

    public function settled(string $tenantId, string $payableId, PaymentBalance $balance): void
    {
        // Nothing to store: the session's balance is always read from Payments.
    }
}
