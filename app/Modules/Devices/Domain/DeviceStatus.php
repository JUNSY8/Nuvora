<?php

declare(strict_types=1);

namespace App\Modules\Devices\Domain;

enum DeviceStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Degraded = 'degraded';
    case Unknown = 'unknown';
    case Maintenance = 'maintenance';

    /**
     * Online, offline and degraded come from Nuvora Edge heartbeats. People
     * can only take a device into maintenance or hand it back to monitoring,
     * which starts again from unknown until the next report.
     *
     * @return list<self>
     */
    public function manualTransitions(): array
    {
        return $this === self::Maintenance ? [self::Unknown] : [self::Maintenance];
    }

    public function canBeSetManuallyTo(self $target): bool
    {
        return in_array($target, $this->manualTransitions(), true);
    }
}
