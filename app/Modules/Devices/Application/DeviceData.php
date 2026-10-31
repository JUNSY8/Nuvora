<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

use App\Modules\Devices\Domain\DeviceType;

final class DeviceData
{
    public function __construct(
        public readonly string $parkingLotId,
        public readonly ?string $gateId,
        public readonly string $name,
        public readonly string $code,
        public readonly DeviceType $type,
        public readonly ?string $vendor,
        public readonly ?string $model,
        public readonly ?string $serialNumber,
        public readonly ?string $ipAddress,
        public readonly ?string $firmwareVersion,
    ) {}
}
