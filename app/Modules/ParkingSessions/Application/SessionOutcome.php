<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;

/**
 * Result of an idempotent operation: a replay returns the session the first
 * request produced instead of applying the operation again.
 */
final class SessionOutcome
{
    /**
     * @param  list<array{type: string, message: string}>  $notices  what the operator should know (membership coverage...)
     */
    public function __construct(
        public readonly ParkingSession $session,
        public readonly bool $replayed,
        public readonly array $notices = [],
    ) {}
}
