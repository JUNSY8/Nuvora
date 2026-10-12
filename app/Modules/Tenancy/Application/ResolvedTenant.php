<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

final readonly class ResolvedTenant
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $timezone,
        public array $roles,
        public array $permissions,
    ) {}
}
