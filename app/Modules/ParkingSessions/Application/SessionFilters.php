<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\ParkingSessions\Domain\SessionStatus;

final class SessionFilters
{
    /**
     * @param  string|null  $fromDate  Y-m-d in the lot's timezone; requires $lotId
     * @param  string|null  $toDate  Y-m-d in the lot's timezone; requires $lotId
     */
    public function __construct(
        public readonly ?string $lotId = null,
        public readonly ?SessionStatus $status = null,
        public readonly ?string $search = null,
        public readonly ?string $vehicleId = null,
        public readonly ?string $fromDate = null,
        public readonly ?string $toDate = null,
    ) {}
}
