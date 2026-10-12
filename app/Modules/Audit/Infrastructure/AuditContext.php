<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure;

final class AuditContext
{
    public ?string $ipAddress = null;

    public ?string $userAgent = null;

    public ?string $requestId = null;

    public function clear(): void
    {
        $this->ipAddress = null;
        $this->userAgent = null;
        $this->requestId = null;
    }
}
