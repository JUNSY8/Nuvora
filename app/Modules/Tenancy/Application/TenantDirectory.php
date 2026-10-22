<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Infrastructure\Persistence\Tenant;

/**
 * Public contract of the Tenancy module for processes that run without a
 * request, such as scheduled renewals that need each tenant's local day.
 */
final class TenantDirectory
{
    public function timezone(string $tenantId): string
    {
        $timezone = Tenant::query()->whereKey($tenantId)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
    }
}
