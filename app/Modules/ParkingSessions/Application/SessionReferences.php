<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Memberships\Application\MembershipDirectory;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\ParkingSessions\Domain\ChargeStatus;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Payments\Application\PaymentDirectory;
use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Reservations\Application\ReservationDirectory;

/**
 * Resolves, with one query per module, the lots, gates, customers,
 * reservations, memberships and payments a page of sessions points to.
 * Only summaries cross the module boundaries.
 */
final class SessionReferences
{
    /**
     * @param  array<string, array{id: string, name: string, code: string}>  $lots
     * @param  array<string, array{id: string, name: string, code: string}>  $gates
     * @param  array<string, array{id: string, name: string, type: string, status: string}>  $customers
     * @param  array<string, array{id: string, code: string, status: string}>  $reservations
     * @param  array<string, array{id: string, code: string, plan: string}>  $memberships
     * @param  array<string, PaymentBalance>  $payments  balances of the stays that charge money
     */
    private function __construct(
        public readonly array $lots,
        public readonly array $gates,
        public readonly array $customers,
        public readonly array $reservations,
        public readonly array $memberships,
        public readonly array $payments,
    ) {}

    /**
     * @param  iterable<ParkingSession>  $sessions
     */
    public static function for(string $tenantId, iterable $sessions): self
    {
        $lots = [];
        $gates = [];
        $customers = [];
        $reservations = [];
        $memberships = [];
        $charges = [];

        foreach ($sessions as $session) {
            $lots[(string) $session->parking_lot_id] = true;

            foreach ([$session->entry_gate_id, $session->exit_gate_id] as $gate) {
                if ($gate !== null) {
                    $gates[(string) $gate] = true;
                }
            }

            if ($session->customer_id !== null) {
                $customers[(string) $session->customer_id] = true;
            }

            if ($session->reservation_id !== null) {
                $reservations[(string) $session->reservation_id] = true;
            }

            if ($session->membership_id !== null) {
                $memberships[(string) $session->membership_id] = true;
            }

            if ($session->charge_status === ChargeStatus::Priced && (int) $session->total_amount > 0) {
                $charges[(string) $session->id] = (int) $session->total_amount;
            }
        }

        $parking = app(ParkingInfrastructureDirectory::class);

        return new self(
            $parking->lotSummaries($tenantId, array_keys($lots)),
            $parking->gateSummaries($tenantId, array_keys($gates)),
            app(CustomerDirectory::class)->summaries($tenantId, array_keys($customers)),
            app(ReservationDirectory::class)->summaries($tenantId, array_keys($reservations)),
            app(MembershipDirectory::class)->summaries($tenantId, array_keys($memberships)),
            app(PaymentDirectory::class)->balances($tenantId, PayableType::ParkingSession, $charges),
        );
    }
}
