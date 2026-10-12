<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure;

use Illuminate\Support\Facades\DB;

final class TenantDatabaseSession
{
    public function apply(?string $tenantId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('select set_config(\'nuvora.tenant_id\', ?, false)', [$tenantId ?? '']);
    }
}
