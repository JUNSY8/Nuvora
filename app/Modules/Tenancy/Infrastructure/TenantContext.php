<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure;

final class TenantContext
{
    private ?string $tenantId = null;

    /** @var list<string> */
    private array $permissions = [];

    /**
     * @param  list<string>  $permissions
     */
    public function set(string $tenantId, array $permissions): void
    {
        $this->tenantId = $tenantId;
        $this->permissions = $permissions;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->permissions = [];
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
