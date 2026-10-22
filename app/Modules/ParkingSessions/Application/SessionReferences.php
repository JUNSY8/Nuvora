<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;

/**
 * Resolves, with one query per module, the lots, gates and customers a page
 * of sessions points to. Only summaries cross the module boundaries.
 */
final class SessionReferences
{
    /**
     * @param  array<string, array{id: string, name: string, code: string}>  $lots
     * @param  array<string, array{id: string, name: string, code: string}>  $gates
     * @param  array<string, array{id: string, name: string, type: string, status: string}>  $customers
     */
    private function __construct(
        public readonly array $lots,
        public readonly array $gates,
        public readonly array $customers,
    ) {}

    /**
     * @param  iterable<ParkingSession>  $sessions
     */
    public static function for(string $tenantId, iterable $sessions): self
    {
        $lots = [];
        $gates = [];
        $customers = [];

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
        }

        $parking = app(ParkingInfrastructureDirectory::class);

        return new self(
            $parking->lotSummaries($tenantId, array_keys($lots)),
            $parking->gateSummaries($tenantId, array_keys($gates)),
            app(CustomerDirectory::class)->summaries($tenantId, array_keys($customers)),
        );
    }
}
